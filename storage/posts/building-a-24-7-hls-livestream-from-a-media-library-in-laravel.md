---
title: "Building a 24/7 HLS Livestream from a Media Library in Laravel"
date: 2026-01-16
excerpt: "Turn a video library into a synchronized 24/7 broadcast with pre-transcoded HLS segments and dynamic playlist generation. All viewers see the same content at the same time."
tags: [laravel, streaming, ffmpeg, hls, video, tutorial]
slug: building-a-24-7-hls-livestream-from-a-media-library-in-laravel
---

:::note
This is a tech demo and would need more thought for a production system.
:::

I had a collection of live performance videos I'd gathered over the years—concert recordings, festival sets, intimate venue sessions. They sat in folders, rarely watched, because opening a file browser and choosing what to play felt like work. What I actually wanted was a TV channel. Tune in, see a band playing live, no decisions required.

There's something exhausting about the modern streaming experience. Everything is on-demand, which sounds like freedom but often feels like a burden. Netflix asks what you want to watch. Spotify asks what you want to hear. Every interaction starts with a choice, and choice fatigue is real. Sometimes you just want to put something on and let it play.

Old TV understood this well. You'd flip to a channel, and something was already happening. You didn't pick the show—you discovered it mid-stream. There was a serendipity to it, a shared experience with whoever else was watching at that moment.

So I built a system that turns a video library into a 24/7 broadcast. All viewers see the same content at the same time. Late joiners sync up immediately. It's not video-on-demand—it's a channel.

The idea quickly expanded beyond live music. The same architecture could power a retro TV channel cycling through old cartoons and classic shows for a nostalgia hit. Or a study channel with lo-fi visuals. Or a virtual fireplace that's always burning. Any collection of videos becomes a synchronized broadcast.

If you want to turn a video library into a continuous broadcast, the architecture is simpler than you'd expect. Pre-transcode videos to HLS segments once, then generate playlists dynamically based on the current time.

This post walks through the system: how segments are generated, how playlists are built on each request, and how to build a dashboard showing what's currently playing.

## How HLS Works (The 30-Second Version)

HTTP Live Streaming breaks video into small chunks (typically 2-10 seconds each) and lists them in a playlist file (`.m3u8`). Client-side players (e.g. Video.js) fetch the playlist, then download and play segments in order. For live streams, players periodically re-fetch the playlist to discover new segments.

The key insight: we can generate all the chunks ahead of time. When a viewer connects, the server checks the current time, calculates which chunk should be playing right now, and hands them a playlist starting from that point. No real-time encoding—just serving pre-made files with a bit of time-based math.

```plaintext
PRE-TRANSCODE (once)
════════════════════════════════════════════════════════════════

  Video.mp4  ──▶  FFmpeg  ──▶  [chunk0] [chunk1] [chunk2] [chunk3] ...
                                  10s      10s      10s      10s


PLAYBACK (each request)
════════════════════════════════════════════════════════════════

  Viewer connects at 2:35pm
           │
           ▼
  ┌─────────────────────────────────────┐
  │  "It's 2:35pm, schedule says       │
  │   chunk 47 is playing right now"    │
  │                                     │
  └─────────────────────────────────────┘
           │
           ▼
  Playlist: start at chunk 47 ──▶  [47] [48] [49] [50] ...
                                    ▲
                                    │
                              viewer joins here
```

## The Architecture

```plaintext
INGESTION (once per video)
Video File ──▶ FFmpeg ──▶ HLS Segments + Playlist (stored permanently)

PLAYBACK (each request)
Current Time ──▶ Calculate Position ──▶ Generate Playlist ──▶ Serve Segments
```

Videos are transcoded ahead of time. When a viewer requests the stream, Laravel calculates what should be playing "now" and generates a playlist pointing to the appropriate segments.

### Storage Structure

```plaintext
storage/app/streams/videos/
├── 1/
│   ├── playlist.m3u8        # Original FFmpeg playlist (for parsing durations)
│   ├── segment_00000.ts
│   ├── segment_00001.ts
│   └── ...
├── 2/
│   └── ...
```

## Database Schema

Track transcoding status on your videos:

```php
Schema::table('videos', function (Blueprint $table) {
    $table->boolean('transcoded')->default(false);
    $table->unsignedInteger('segment_count')->nullable();
    $table->decimal('hls_duration', 10, 3)->nullable();  // Total duration in seconds
});
```

With a scope for stream-ready videos:

```php
public function scopeStreamReady($query)
{
    return $query->where('transcoded', true)
        ->where('hls_duration', '>', 0);
}
```

## Transcoding Videos

The FFmpeg command converts videos to HLS segments:

```php
$outputDir = storage_path("app/streams/videos/{$video->id}");
$playlistPath = "{$outputDir}/playlist.m3u8";
$segmentPattern = "{$outputDir}/segment_%05d.ts";
$progressFile = "{$outputDir}/progress.txt";

$result = Process::run([
    'ffmpeg', '-i', $inputPath,
    '-progress', $progressFile,  // For tracking transcode progress
    // Normalize video: 720p, maintain aspect ratio, pad to exact dimensions
    // Also normalize framerate and pixel format for consistent output
    '-vf', 'scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,fps=30,format=yuv420p',
    // Video encoding
    '-c:v', 'libx264',
    '-preset', 'veryfast',
    '-crf', '23',
    // Audio normalization and encoding
    '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11',  // Normalize loudness across videos
    '-c:a', 'aac',
    '-b:a', '128k',
    '-ac', '2',
    '-ar', '44100',  // Consistent sample rate
    // HLS options
    '-hls_time', '10',
    '-hls_list_size', '0',  // Keep ALL segments in playlist
    '-hls_segment_filename', $segmentPattern,
    '-f', 'hls',
    $playlistPath,
]);
```

Let's break down the critical flags:

**`-hls_list_size 0`**: By default, FFmpeg keeps only a rolling window of segments (5 by default). Setting this to `0` keeps all segments in the playlist permanently. This is essential—we need every segment to exist for random access.

**`loudnorm` filter**: When playing videos back-to-back, inconsistent audio levels are jarring. The loudnorm filter normalizes audio to broadcast standards (-16 LUFS integrated loudness), so viewers don't need to adjust volume between videos.

**`fps=30,format=yuv420p`**: Normalizes framerate and pixel format across all videos. Source videos may have varying framerates (24, 25, 29.97, 30, 60 fps), which can cause playback issues at video boundaries. Consistent encoding parameters ensure smooth transitions.

**`-ar 44100`**: Sets a consistent audio sample rate. Like framerate, inconsistent sample rates between videos can cause audio glitches at transitions.

After transcoding, parse the playlist to update the database:

```php
$playlistPath = "{$outputDir}/playlist.m3u8";
$content = file_get_contents($playlistPath);

// Parse segment durations from #EXTINF tags
$durations = [];
preg_match_all('/#EXTINF:([\d.]+),/', $content, $matches);
$durations = array_map('floatval', $matches[1]);

$video->update([
    'transcoded' => true,
    'segment_count' => count($durations),
    'hls_duration' => array_sum($durations),
]);
```

Note: We parse the actual `#EXTINF` durations rather than assuming exactly 10 seconds per segment. The last segment is often shorter, and keyframe alignment can cause slight variations.

## Dynamic Playlist Generation

The core of the system. On each request, calculate what should be playing and build a playlist:

```php
class StreamService
{
    protected int $scheduleEpoch;

    public function __construct()
    {
        // All viewers sync to the same reference point
        $this->scheduleEpoch = Carbon::today('UTC')->timestamp;
    }

    public function findCurrentPosition(int $timeAdjustment = 0): ?array
    {
        $videos = Video::streamReady()->orderBy('id')->get();
        if ($videos->isEmpty()) {
            return null;
        }

        $schedule = $this->buildSchedule($videos);

        // How far into the loop are we?
        // timeAdjustment allows offsetting to sync guide display with actual player position
        $elapsed = now()->utc()->timestamp - $this->scheduleEpoch + $timeAdjustment;
        $position = fmod((float) $elapsed, $schedule['total_duration']);

        // Find which video is playing at this position
        foreach ($schedule['entries'] as $index => $entry) {
            if ($position >= $entry['start'] && $position < $entry['end']) {
                $offset = $position - $entry['start'];
                $video = $entry['video'];

                // Calculate segment index from actual duration, not fixed value
                $avgSegmentDuration = $video->hls_duration / max(1, $video->segment_count);

                return [
                    'video' => $video,
                    'video_index' => $index,
                    'offset' => $offset,
                    'segment_index' => (int) floor($offset / $avgSegmentDuration),
                    'schedule' => $schedule,
                ];
            }
        }

        return null;
    }
}
```

The key insight: `fmod()` wraps elapsed time into the total schedule duration. When you reach the end of all videos, it loops back to the beginning. Every viewer calculating position at the same timestamp gets the same result.

### Building the Schedule

A deterministic shuffle ensures all viewers see the same order:

```php
protected function buildSchedule(Collection $videos): array
{
    // Same seed = same order for all viewers today
    $seed = (int) Carbon::today('UTC')->format('Ymd');
    $shuffled = $this->seededShuffle($videos->all(), $seed);

    $entries = [];
    $offset = 0.0;

    foreach ($shuffled as $video) {
        $entries[] = [
            'video' => $video,
            'start' => $offset,
            'end' => $offset + $video->hls_duration,
        ];
        $offset += $video->hls_duration;
    }

    return [
        'entries' => $entries,
        'total_duration' => $offset,
    ];
}

protected function seededShuffle(array $items, int $seed): array
{
    // Fisher-Yates shuffle with seeded RNG
    mt_srand($seed);
    
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    
    // Reset to avoid affecting other random operations
    mt_srand();
    
    return $items;
}
```

Why `mt_srand()`? PHP's Mersenne Twister PRNG produces identical sequences for the same seed across all PHP installations. This means every server instance, every request, generates the exact same video order for a given day.

The shuffle changes daily (seed is `YYYYMMDD`), giving viewers variety while maintaining synchronization within each day.

:::note
The shuffle_daily approach shown here is just one option. The system can support multiple ordering modes:
- **sequential**: Videos play in ID order
- **shuffle_daily**: Random order frozen for the day (shown above)
- **shuffle_always**: Random every request (viewers may see different orders)
- **manual**: Curator-defined order via admin interface

Each mode has trade-offs between predictability, variety, and editorial control. The daily shuffle balances variety with the shared viewing experience.
:::

### Generating the Playlist

Build the m3u8 content from the current position:

```php
public function generateDynamicPlaylist(): ?string
{
    $position = $this->findCurrentPosition();
    if (!$position) {
        return null;
    }

    $schedule = $position['schedule'];
    $entries = $schedule['entries'];
    $currentVideoIndex = $position['video_index'];
    $currentSegmentIndex = $position['segment_index'];

    // Configuration: how many segments behind/ahead to include
    $segmentsBehind = config('streaming.live_segments_behind', 3);
    $segmentsAhead = config('streaming.live_segments_ahead', 15);

    // Calculate media sequence: count all segments up to current position
    $mediaSequence = 0;
    for ($i = 0; $i < $currentVideoIndex; $i++) {
        $mediaSequence += $entries[$i]['video']->segment_count;
    }
    $mediaSequence += max(0, $currentSegmentIndex - $segmentsBehind);

    // Collect segments from current and subsequent videos
    $segments = [];
    $segmentsNeeded = $segmentsBehind + $segmentsAhead;
    $startSegment = max(0, $currentSegmentIndex - $segmentsBehind);
    $videoIndex = $currentVideoIndex;

    while (count($segments) < $segmentsNeeded && $videoIndex < count($entries)) {
        $video = $entries[$videoIndex]['video'];
        $segmentStart = ($videoIndex === $currentVideoIndex) ? $startSegment : 0;

        // Read actual durations from the HLS playlist file
        $segmentDurations = $this->getSegmentDurations($video);

        for ($seg = $segmentStart; $seg < $video->segment_count && count($segments) < $segmentsNeeded; $seg++) {
            $segments[] = [
                'video_id' => $video->id,
                'segment_index' => $seg,
                'duration' => $segmentDurations[$seg] ?? 10.0,
            ];
        }

        $videoIndex++;

        // Handle looping back to first video
        if ($videoIndex >= count($entries) && count($segments) < $segmentsNeeded) {
            $videoIndex = 0;
        }
    }

    if (empty($segments)) {
        return null;
    }

    // Build playlist
    $maxDuration = (int) ceil(max(array_column($segments, 'duration')));

    $playlist = "#EXTM3U\n";
    $playlist .= "#EXT-X-VERSION:3\n";
    $playlist .= "#EXT-X-TARGETDURATION:{$maxDuration}\n";
    $playlist .= "#EXT-X-MEDIA-SEQUENCE:{$mediaSequence}\n";

    $lastVideoId = null;
    foreach ($segments as $seg) {
        // Signal encoding changes between videos
        if ($lastVideoId !== null && $seg['video_id'] !== $lastVideoId) {
            $playlist .= "#EXT-X-DISCONTINUITY\n";
        }
        $lastVideoId = $seg['video_id'];

        $playlist .= sprintf("#EXTINF:%.6f,\n", $seg['duration']);
        $playlist .= sprintf("videos/%d/segment_%05d.ts\n", $seg['video_id'], $seg['segment_index']);
    }

    return $playlist;
}
```

A few important details here:

**`EXT-X-MEDIA-SEQUENCE`**: This number identifies the first segment in the playlist. For live streams, it must increase monotonically as old segments are removed. Players use this to detect playlist updates and avoid re-downloading segments. We calculate it by counting all segments before the current position.

**`EXT-X-DISCONTINUITY`**: This tag signals that the next segment has different encoding parameters—different video, codec settings, timestamps, etc. Without it, players may glitch or fail when crossing video boundaries. The HLS spec requires this whenever there's a significant change in encoding characteristics.

**No `EXT-X-ENDLIST`**: Omitting this tag tells players this is a live stream. They'll periodically re-fetch the playlist to discover new segments.

### Reading Segment Durations from the Playlist

Rather than estimating segment durations, read them directly from the generated HLS playlist:

```php
protected function getSegmentDurations(Video $video): array
{
    $playlistPath = storage_path("app/streams/videos/{$video->id}/playlist.m3u8");

    if (!file_exists($playlistPath)) {
        // Fallback to default durations if playlist missing
        return array_fill(0, $video->segment_count, 10.0);
    }

    $content = file_get_contents($playlistPath);
    $durations = [];

    foreach (explode("\n", $content) as $line) {
        if (str_starts_with($line, '#EXTINF:')) {
            if (preg_match('/#EXTINF:([\d.]+)/', $line, $matches)) {
                $durations[] = (float) $matches[1];
            }
        }
    }

    return $durations ?: array_fill(0, $video->segment_count, 10.0);
}
```

This approach is more accurate than estimating. While most segments are close to the target duration (10 seconds), the last segment of each video is typically shorter, and keyframe alignment can cause slight variations. Reading the actual `#EXTINF` values ensures the dynamic playlist has correct timing.

## Serving the Stream

```php
// routes/web.php
Route::get('/stream/playlist.m3u8', [StreamController::class, 'playlist']);
Route::get('/stream/videos/{videoId}/segment_{filename}', [StreamController::class, 'segment'])
    ->where('filename', '[0-9]+\.ts');
```

```php
class StreamController extends Controller
{
    public function playlist(StreamService $stream): Response
    {
        $content = $stream->generateDynamicPlaylist();

        if (!$content) {
            return response('No content available', 503)
                ->header('Retry-After', '60');
        }

        return response($content, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function segment(int $videoId, string $filename): StreamedResponse
    {
        $path = storage_path("app/streams/videos/{$videoId}/segment_{$filename}");
        
        if (!file_exists($path)) {
            abort(404);
        }

        return response()->stream(
            function () use ($path) {
                $handle = fopen($path, 'rb');
                fpassthru($handle);
                fclose($handle);
            },
            200,
            [
                'Content-Type' => 'video/MP2T',
                'Content-Length' => filesize($path),
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]
        );
    }
}
```

The caching strategy is intentional:

- **Playlists**: Never cached. They change every few seconds as time advances.
- **Segments**: Cached forever. They're immutable—segment 00042 for video 7 will always contain the same bytes.

This makes CDN deployment straightforward. Segments get cached at the edge, while playlists always hit your origin.

## Dashboard: Now Playing

Track what's currently streaming with a progress indicator:

```php
// StreamService.php
public function getNowPlaying(): ?object
{
    // Advance the guide position to match where HLS.js is actually playing
    // The playlist window and player buffering create an offset between
    // "current time" and what the viewer sees on screen
    $guideAdvance = $this->calculateGuideAdvanceSeconds();
    $position = $this->findCurrentPosition($guideAdvance);

    if (!$position) {
        return null;
    }

    $video = $position['video'];
    $offset = $position['offset'];

    return (object) [
        'video' => $video,
        'video_title' => $video->video_title,
        'thumbnail_url' => $video->thumbnail_url,
        'position' => $this->formatDuration((int) $offset),
        'duration' => $this->formatDuration((int) $video->hls_duration),
        'progress_percent' => ($video->hls_duration > 0)
            ? round(($offset / $video->hls_duration) * 100, 1)
            : 0,
    ];
}

protected function calculateGuideAdvanceSeconds(): int
{
    // The HLS playlist includes segments behind and ahead of "current time"
    // HLS.js plays a few segments back from the live edge (liveSyncDurationCount)
    // This calculates the net offset so the guide shows what's actually playing
    $segmentsBehind = config('streaming.live_segments_behind', 3);
    $segmentsAhead = config('streaming.live_segments_ahead', 15);
    $liveSyncSegments = config('streaming.player_live_sync_segments', 3);
    $segmentDuration = 10;

    $netSegmentOffset = ($segmentsAhead - $segmentsBehind) - $liveSyncSegments;
    return (int) ($netSegmentOffset * $segmentDuration);
}

protected function formatDuration(int $seconds): string
{
    if ($seconds >= 3600) {
        return sprintf('%d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }

    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}
```

The guide sync calculation is subtle but important. When a viewer looks at the "Now Playing" widget, it should match what they're actually seeing in the player—not what the server calculates as "current." The HLS protocol's segment windowing and player-side buffering create an offset that needs compensation.

### Dashboard Controller

```php
public function index(StreamService $stream): View
{
    $transcodedVideos = Video::where('transcoded', true)->count();
    $totalDuration = Video::where('transcoded', true)->sum('hls_duration');
    
    return view('dashboard', [
        'stats' => [
            'transcoded_videos' => $transcodedVideos,
            'pending_transcode' => Video::where('transcoded', false)->count(),
            'total_segments' => Video::sum('segment_count'),
            'stream_duration' => $totalDuration,
            'loops_per_day' => $totalDuration > 0 
                ? round(86400 / $totalDuration, 1) 
                : 0,
        ],
        'nowPlaying' => $stream->getNowPlaying(),
        'streamUrl' => url('/stream/playlist.m3u8'),
    ]);
}
```

### Dashboard View

```blade
<div class="rounded-xl border bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Live Stream</h2>
        @if($nowPlaying)
            <span class="inline-flex items-center gap-2 rounded-full bg-red-100 px-3 py-1 text-sm font-medium text-red-700">
                <span class="h-2 w-2 animate-pulse rounded-full bg-red-500"></span>
                Live
            </span>
        @endif
    </div>

    @if($nowPlaying)
        <div class="flex gap-6">
            @if($nowPlaying->thumbnail_url)
                <img src="{{ $nowPlaying->thumbnail_url }}" 
                     alt="{{ $nowPlaying->video_title }}"
                     class="w-48 h-28 object-cover rounded-lg" />
            @endif
            
            <div class="flex-1 min-w-0">
                <p class="font-medium text-gray-900 truncate">
                    {{ $nowPlaying->video_title }}
                </p>
                <p class="text-sm text-gray-500 mt-1">
                    {{ $nowPlaying->position }} / {{ $nowPlaying->duration }}
                </p>
                
                <div class="mt-3 h-2 w-full rounded-full bg-gray-200 overflow-hidden">
                    <div class="h-full rounded-full bg-blue-600 transition-all duration-1000"
                         style="width: {{ $nowPlaying->progress_percent }}%"></div>
                </div>
                
                @if($nowPlaying->next_video)
                    <p class="text-xs text-gray-400 mt-2">
                        Up next: {{ $nowPlaying->next_video }}
                    </p>
                @endif
                
                <div class="mt-4 flex items-center gap-2">
                    <code class="flex-1 rounded bg-gray-100 px-3 py-2 text-sm font-mono text-gray-700 truncate">
                        {{ $streamUrl }}
                    </code>
                    <button onclick="navigator.clipboard.writeText('{{ $streamUrl }}')"
                            class="rounded bg-gray-100 px-3 py-2 text-sm hover:bg-gray-200">
                        Copy
                    </button>
                </div>
            </div>
        </div>
    @else
        <div class="text-center py-8">
            <p class="text-gray-500">No videos ready for streaming.</p>
            <p class="text-sm text-gray-400 mt-1">Transcode some videos to get started.</p>
        </div>
    @endif
</div>

<div class="grid gap-4 md:grid-cols-4 mt-6">
    <div class="rounded-xl border bg-white p-4">
        <h3 class="text-sm font-medium text-gray-500">Videos Ready</h3>
        <p class="text-2xl font-bold text-gray-900 mt-1">
            {{ number_format($stats['transcoded_videos']) }}
        </p>
    </div>
    <div class="rounded-xl border bg-white p-4">
        <h3 class="text-sm font-medium text-gray-500">Total Duration</h3>
        <p class="text-2xl font-bold text-gray-900 mt-1">
            {{ gmdate('H:i:s', $stats['stream_duration']) }}
        </p>
    </div>
    <div class="rounded-xl border bg-white p-4">
        <h3 class="text-sm font-medium text-gray-500">Loops/Day</h3>
        <p class="text-2xl font-bold text-gray-900 mt-1">
            {{ $stats['loops_per_day'] }}×
        </p>
    </div>
    <div class="rounded-xl border bg-white p-4">
        <h3 class="text-sm font-medium text-gray-500">Pending</h3>
        <p class="text-2xl font-bold text-gray-900 mt-1">
            {{ number_format($stats['pending_transcode']) }}
        </p>
    </div>
</div>
```

## Testing the Stream

You can test with VLC, ffplay, or any HLS-compatible player:

```bash
# VLC
vlc http://localhost:8000/stream/playlist.m3u8

# ffplay (comes with FFmpeg)
ffplay http://localhost:8000/stream/playlist.m3u8

# Or use hls.js in a browser
```

For debugging, curl the playlist directly:

```bash
curl http://localhost:8000/stream/playlist.m3u8
```

You should see something like:

```
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:10
#EXT-X-MEDIA-SEQUENCE:4523
#EXTINF:10.000000,
videos/3/segment_00007.ts
#EXTINF:10.000000,
videos/3/segment_00008.ts
#EXT-X-DISCONTINUITY
#EXTINF:10.000000,
videos/1/segment_00000.ts
```

## Why This Works

**Synchronized viewing.** The deterministic schedule (seeded shuffle + time-based position) ensures all viewers see the same content. Two people opening the stream at the same moment see identical video, regardless of which server handles their request.

**Late joiners sync immediately.** There's no concept of "catching up." When you join, you calculate where in the schedule we are *right now* and start there. You're immediately watching what everyone else is watching.

**No real-time encoding.** Transcoding happens once, ahead of time. Playback is just serving static files and generating small text playlists. A modest server can handle many concurrent viewers.

**Seamless video transitions.** The `#EXT-X-DISCONTINUITY` tag tells players to reinitialize their decoder at video boundaries. Without encoding normalization (same resolution, codec settings), you might see brief glitches—but playback continues.

**CDN-friendly.** Segment files are immutable and heavily cacheable. Only playlists need to hit your origin, and they're tiny text files.

## Gotchas and Limitations

**Segment duration accuracy.** HLS can only cut at keyframes. Even with forced keyframe intervals, actual segment duration varies slightly. The playlist parser reads real `#EXTINF` values to handle this.

**Schedule changes.** If you add or remove videos, the schedule changes. Viewers might experience a discontinuity or jump. For a production system, you'd want to version schedules or make changes during low-traffic periods.

**Clock synchronization.** All servers must have synchronized clocks (use NTP). A few seconds of drift means viewers on different servers see different content.

**HLS latency.** Standard HLS has 15-30 seconds of inherent latency due to segment buffering. For a 24/7 channel this doesn't matter—but it means this architecture isn't suitable for live sports or auctions where real-time matters. For lower latency, you'd need Low-Latency HLS (LL-HLS), which is a different beast entirely.

**Storage requirements.** HLS segments are larger than the original video due to per-segment overhead. Expect roughly 10-15% size increase. For a large library, storage adds up.

## Production Considerations

**CDN setup.** Put a CDN in front of your origin. Configure it to:
- Cache `.ts` files aggressively (immutable, cache forever)
- Never cache `.m3u8` files (or cache for only 1-2 seconds)

**Monitoring.** Track playlist generation time, segment 404s, and concurrent viewers. A spike in 404s might indicate a transcoding issue or missing segments.

**Graceful degradation.** If no videos are transcoded, return a 503 with `Retry-After` header rather than an empty playlist.

**Background transcoding.** Use Laravel's queue system for transcoding. A dedicated worker with access to FFmpeg can process videos without blocking web requests.

## When to Consider Alternatives

**True live content** (concerts happening now, live sports) needs actual live streaming infrastructure—RTMP ingest, real-time transcoding, potentially LL-HLS or WebRTC for low latency.

**Video on demand with seeking** is a different architecture. Users expect to pause, rewind, and skip. That's not what this system does.

**Large audiences** (thousands of concurrent viewers) work fine with a CDN, but you'll want to monitor origin load. Consider pre-generating playlist variants if playlist generation becomes a bottleneck.

**Mobile networks** can be flaky. HLS handles this reasonably well (players buffer segments), but you might want adaptive bitrate streaming with multiple quality levels for production.

## Conclusion

Pre-transcode once, generate playlists dynamically. The result is a 24/7 broadcast that all viewers experience together, running on a standard Laravel deployment.

The architecture is surprisingly simple: FFmpeg turns videos into segments, Laravel serves them with time-based playlist generation, and deterministic shuffling keeps everyone synchronized. No WebSockets, no real-time encoding, no complex state management.

Your video library becomes a TV channel.