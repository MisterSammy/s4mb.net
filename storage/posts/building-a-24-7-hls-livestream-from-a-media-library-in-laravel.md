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

There's something exhausting about the modern streaming paradigm. Everything is on-demand, which sounds like freedom but often feels like a burden. Netflix asks what you want to watch. Spotify asks what you want to hear. Every interaction starts with a choice, and choice fatigue is real. Sometimes you just want to put something on and let it play.

Old TV had this figured out. You'd flip to a channel, and something was already happening. You didn't pick the show—you discovered it mid-stream. There was a serendipity to it, a shared experience with whoever else was watching at that moment.

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
$segmentDuration = 10;  // seconds per segment

$result = Process::run([
    'ffmpeg', '-i', $inputPath,
    // Normalize video: 720p, maintain aspect ratio, pad to exact dimensions
    '-vf', 'scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2',
    // Video encoding
    '-c:v', 'libx264',
    '-preset', 'veryfast',
    '-crf', '23',
    // Force keyframes at segment boundaries for clean cuts
    '-g', (string) ($segmentDuration * 30),  // GOP = segment_duration * framerate
    '-keyint_min', (string) ($segmentDuration * 30),
    '-sc_threshold', '0',  // Disable scene change detection
    // Audio encoding
    '-c:a', 'aac',
    '-b:a', '128k',
    '-ac', '2',
    // HLS options
    '-hls_time', (string) $segmentDuration,
    '-hls_list_size', '0',  // Keep ALL segments in playlist
    '-hls_playlist_type', 'vod',  // Marks as complete (adds EXT-X-ENDLIST)
    '-hls_segment_filename', "{$outputDir}/segment_%05d.ts",
    '-f', 'hls',
    "{$outputDir}/playlist.m3u8",
]);
```

Let's break down the critical flags:

**`-hls_list_size 0`**: By default, FFmpeg keeps only a rolling window of segments (5 by default). Setting this to `0` keeps all segments in the playlist permanently. This is essential—we need every segment to exist for random access.

**`-hls_playlist_type vod`**: Tells FFmpeg this is a complete video, not a live stream. This adds `#EXT-X-ENDLIST` to the playlist and implicitly sets `hls_list_size` to 0.

**`-g` and `-keyint_min`**: Force keyframes at regular intervals matching segment duration. HLS can only cut segments at keyframes, so this ensures consistent segment lengths. Without this, segments might be longer than expected.

**`-sc_threshold 0`**: Disables scene change detection for keyframe insertion. We want keyframes at predictable intervals, not wherever FFmpeg detects a scene change.

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
    protected int $segmentDuration = 10;

    public function __construct()
    {
        // All viewers sync to the same reference point
        $this->scheduleEpoch = Carbon::today('UTC')->timestamp;
    }

    public function findCurrentPosition(): ?array
    {
        $videos = Video::streamReady()->orderBy('id')->get();
        if ($videos->isEmpty()) {
            return null;
        }

        $schedule = $this->buildSchedule($videos);

        // How far into the loop are we?
        $elapsed = now()->utc()->timestamp - $this->scheduleEpoch;
        $position = fmod((float) $elapsed, $schedule['total_duration']);

        // Find which video is playing at this position
        foreach ($schedule['entries'] as $index => $entry) {
            if ($position >= $entry['start'] && $position < $entry['end']) {
                $offset = $position - $entry['start'];
                return [
                    'video' => $entry['video'],
                    'video_index' => $index,
                    'offset' => $offset,
                    'segment_index' => (int) floor($offset / $this->segmentDuration),
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

### Generating the Playlist

Build the m3u8 content from the current position:

```php
public function generateDynamicPlaylist(): ?string
{
    $position = $this->findCurrentPosition();
    if (!$position) {
        return null;
    }

    // Collect segments: some behind (for buffering), more ahead
    $segments = $this->collectSegments(
        position: $position,
        behind: 3,   // 30 seconds of buffer behind current position
        ahead: 15    // 150 seconds ahead
    );

    if (empty($segments)) {
        return null;
    }

    // Calculate media sequence number
    // This must increment monotonically as segments "fall off" the playlist
    $mediaSequence = $this->calculateMediaSequence($position);

    $playlist = "#EXTM3U\n";
    $playlist .= "#EXT-X-VERSION:3\n";
    $playlist .= "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
    $playlist .= "#EXT-X-MEDIA-SEQUENCE:{$mediaSequence}\n";

    $lastVideoId = null;
    foreach ($segments as $seg) {
        // Signal encoding changes between videos
        if ($lastVideoId !== null && $seg['video_id'] !== $lastVideoId) {
            $playlist .= "#EXT-X-DISCONTINUITY\n";
        }
        $lastVideoId = $seg['video_id'];

        $playlist .= "#EXTINF:{$seg['duration']},\n";
        $playlist .= "videos/{$seg['video_id']}/segment_{$seg['index']}.ts\n";
    }

    return $playlist;
}
```

A few important details here:

**`EXT-X-MEDIA-SEQUENCE`**: This number identifies the first segment in the playlist. For live streams, it must increase monotonically as old segments are removed. Players use this to detect playlist updates and avoid re-downloading segments.

**`EXT-X-DISCONTINUITY`**: This tag signals that the next segment has different encoding parameters—different video, codec settings, timestamps, etc. Without it, players may glitch or fail when crossing video boundaries. The HLS spec requires this whenever there's a significant change in encoding characteristics.

**No `EXT-X-ENDLIST`**: Omitting this tag tells players this is a live stream. They'll periodically re-fetch the playlist to discover new segments.

### Collecting Segments Across Video Boundaries

The segment collection needs to handle wrapping across videos and across the entire schedule:

```php
protected function collectSegments(array $position, int $behind, int $ahead): array
{
    $segments = [];
    $schedule = $position['schedule'];
    $totalSegments = $behind + $ahead + 1;
    
    // Start position: current segment minus buffer
    $currentVideoIndex = $position['video_index'];
    $currentSegmentIndex = $position['segment_index'] - $behind;
    
    // Handle negative segment index (need previous video)
    while ($currentSegmentIndex < 0) {
        $currentVideoIndex--;
        if ($currentVideoIndex < 0) {
            $currentVideoIndex = count($schedule['entries']) - 1;  // Wrap to end
        }
        $video = $schedule['entries'][$currentVideoIndex]['video'];
        $currentSegmentIndex += $video->segment_count;
    }
    
    // Collect segments
    $collected = 0;
    while ($collected < $totalSegments) {
        $entry = $schedule['entries'][$currentVideoIndex];
        $video = $entry['video'];
        
        // Get segment duration from stored playlist or estimate
        $duration = $this->getSegmentDuration($video, $currentSegmentIndex);
        
        $segments[] = [
            'video_id' => $video->id,
            'index' => str_pad($currentSegmentIndex, 5, '0', STR_PAD_LEFT),
            'duration' => number_format($duration, 6),
        ];
        
        $currentSegmentIndex++;
        $collected++;
        
        // Move to next video if we've exhausted segments
        if ($currentSegmentIndex >= $video->segment_count) {
            $currentSegmentIndex = 0;
            $currentVideoIndex++;
            if ($currentVideoIndex >= count($schedule['entries'])) {
                $currentVideoIndex = 0;  // Wrap to beginning
            }
        }
    }
    
    return $segments;
}

protected function getSegmentDuration(Video $video, int $index): float
{
    // For accuracy, you could store individual segment durations
    // For simplicity, use target duration (last segment may be shorter)
    if ($index === $video->segment_count - 1) {
        // Last segment: calculate from total duration
        $fullSegments = $video->segment_count - 1;
        return $video->hls_duration - ($fullSegments * $this->segmentDuration);
    }
    
    return (float) $this->segmentDuration;
}
```

### Calculating Media Sequence

The media sequence must be globally consistent and monotonically increasing:

```php
protected function calculateMediaSequence(array $position): int
{
    // Total segments that have "played" since epoch
    $schedule = $position['schedule'];
    $elapsed = now()->utc()->timestamp - $this->scheduleEpoch;
    
    // How many complete schedule loops?
    $completeLoops = (int) floor($elapsed / $schedule['total_duration']);
    
    // Total segments in one complete loop
    $segmentsPerLoop = array_sum(
        array_map(fn($e) => $e['video']->segment_count, $schedule['entries'])
    );
    
    // Segments from complete loops
    $sequenceFromLoops = $completeLoops * $segmentsPerLoop;
    
    // Segments from current partial loop (up to current position)
    $segmentsInCurrentLoop = 0;
    for ($i = 0; $i < $position['video_index']; $i++) {
        $segmentsInCurrentLoop += $schedule['entries'][$i]['video']->segment_count;
    }
    $segmentsInCurrentLoop += $position['segment_index'];
    
    // Subtract buffer segments (we include some segments behind current position)
    return max(0, $sequenceFromLoops + $segmentsInCurrentLoop - 3);
}
```

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
    $position = $this->findCurrentPosition();
    if (!$position) {
        return null;
    }

    $video = $position['video'];
    $nextVideo = $this->getNextVideo($position);

    return (object) [
        'video' => $video,
        'video_title' => $video->title,
        'thumbnail_url' => $video->thumbnail_url,
        'position' => $this->formatDuration((int) $position['offset']),
        'duration' => $this->formatDuration((int) $video->hls_duration),
        'progress_percent' => min(100, round(($position['offset'] / $video->hls_duration) * 100, 1)),
        'time_remaining' => (int) ceil($video->hls_duration - $position['offset']),
        'next_video' => $nextVideo?->title,
    ];
}

protected function getNextVideo(array $position): ?Video
{
    $entries = $position['schedule']['entries'];
    $nextIndex = $position['video_index'] + 1;
    
    if ($nextIndex >= count($entries)) {
        $nextIndex = 0;
    }
    
    return $entries[$nextIndex]['video'] ?? null;
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