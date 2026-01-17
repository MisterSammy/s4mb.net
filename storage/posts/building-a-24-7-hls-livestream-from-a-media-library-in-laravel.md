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

If you want to turn a video library into a continuous broadcast where all viewers see the same content at the same time, the architecture is simpler than you'd expect. Pre-transcode videos to HLS segments once, then generate playlists dynamically based on the current time.

This post walks through the system: how segments are generated, how playlists are built on each request, and how to build a dashboard showing what's currently playing.

## The Architecture

```plaintext
INGESTION (once per video)
Video File ──▶ FFmpeg ──▶ HLS Segments (stored permanently)

PLAYBACK (each request)
Current Time ──▶ Calculate Position ──▶ Generate Playlist ──▶ Serve Segments
```

Videos are transcoded ahead of time. When a viewer requests the stream, Laravel calculates what should be playing "now" and generates a playlist pointing to the appropriate segments.

### Storage Structure

```plaintext
storage/app/streams/videos/
├── 1/
│   ├── playlist.m3u8
│   ├── segment_00000.ts
│   ├── segment_00001.ts
│   └── ...
├── 2/
│   └── ...
```

## Database Schema

Track transcoding status on your videos:

```php
$table->boolean('transcoded')->default(false);
$table->unsignedInteger('segment_count')->nullable();
$table->decimal('hls_duration', 10, 3)->nullable();
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
Process::run([
    'ffmpeg', '-i', $inputPath,
    '-vf', 'scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2',
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
    '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
    '-hls_time', '10',
    '-hls_list_size', '0',  // Keep ALL segments
    '-hls_segment_filename', "{$outputDir}/segment_%05d.ts",
    '-f', 'hls', "{$outputDir}/playlist.m3u8",
]);
```

The critical flag is `-hls_list_size 0`. By default, FFmpeg keeps only a rolling window of segments. Setting this to `0` keeps all segments permanently.

After transcoding, parse the playlist to update the database:

```php
$content = file_get_contents("{$outputDir}/playlist.m3u8");

$video->update([
    'transcoded' => true,
    'segment_count' => substr_count($content, '#EXTINF:'),
    'hls_duration' => collect(explode("\n", $content))
        ->filter(fn ($line) => str_starts_with($line, '#EXTINF:'))
        ->sum(fn ($line) => (float) substr($line, 8)),
]);
```

## Dynamic Playlist Generation

The core of the system. On each request, calculate what should be playing and build a playlist:

```php
class StreamService
{
    protected int $scheduleEpoch;

    public function __construct()
    {
        $this->scheduleEpoch = now()->utc()->startOfDay()->timestamp;
    }

    public function findCurrentPosition(): ?array
    {
        $videos = Video::streamReady()->orderBy('id')->get();
        if ($videos->isEmpty()) return null;

        $schedule = $this->buildSchedule($videos);

        // Where in the loop are we?
        $elapsed = now()->utc()->timestamp - $this->scheduleEpoch;
        $position = fmod((float) $elapsed, $schedule['total_duration']);

        // Find the video playing at this position
        foreach ($schedule['entries'] as $index => $entry) {
            if ($position >= $entry['start'] && $position < $entry['end']) {
                $offset = $position - $entry['start'];
                return [
                    'video' => $entry['video'],
                    'video_index' => $index,
                    'offset' => $offset,
                    'segment_index' => (int) floor($offset / 10),
                    'schedule' => $schedule,
                ];
            }
        }

        return null;
    }
}
```

### Building the Schedule

A deterministic shuffle ensures all viewers see the same order:

```php
protected function buildSchedule(Collection $videos): array
{
    $seed = (int) date('Ymd');  // Same order all day
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

    return ['entries' => $entries, 'total_duration' => $offset];
}

protected function seededShuffle(array $items, int $seed): array
{
    mt_srand($seed);
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    mt_srand();
    return $items;
}
```

### Generating the Playlist

Build the m3u8 content from the current position:

```php
public function generateDynamicPlaylist(): ?string
{
    $position = $this->findCurrentPosition();
    if (!$position) return null;

    $segments = $this->collectSegments($position, behind: 3, ahead: 15);

    $playlist = "#EXTM3U\n#EXT-X-VERSION:3\n";
    $playlist .= "#EXT-X-TARGETDURATION:10\n";
    $playlist .= "#EXT-X-MEDIA-SEQUENCE:{$this->calculateMediaSequence($position)}\n";

    $lastVideoId = null;
    foreach ($segments as $seg) {
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

The `#EXT-X-DISCONTINUITY` tag signals video transitions, letting players handle the switch smoothly.

## Serving the Stream

```php
class StreamController extends Controller
{
    public function playlist(StreamService $stream): Response
    {
        $content = $stream->generateDynamicPlaylist();

        if (!$content) {
            return response('No content available', 503);
        }

        return response($content, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache, no-store',
        ]);
    }

    public function segment(int $videoId, string $filename): StreamedResponse
    {
        $path = storage_path("app/streams/videos/{$videoId}/{$filename}");

        return response()->stream(
            fn () => fpassthru(fopen($path, 'rb')),
            200,
            [
                'Content-Type' => 'video/MP2T',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]
        );
    }
}
```

Playlists are never cached (they change constantly). Segments are immutable and cached forever.

## Dashboard: Now Playing

Track what's currently streaming with a progress indicator:

```php
// StreamService.php
public function getNowPlaying(): ?object
{
    $position = $this->findCurrentPosition();
    if (!$position) return null;

    $video = $position['video'];

    return (object) [
        'video_title' => $video->title,
        'thumbnail_url' => $video->thumbnail_url,
        'position' => $this->formatDuration((int) $position['offset']),
        'duration' => $this->formatDuration((int) $video->hls_duration),
        'progress_percent' => round(($position['offset'] / $video->hls_duration) * 100, 1),
    ];
}

protected function formatDuration(int $seconds): string
{
    return $seconds >= 3600
        ? sprintf('%d:%02d:%02d', $seconds / 3600, ($seconds % 3600) / 60, $seconds % 60)
        : sprintf('%d:%02d', $seconds / 60, $seconds % 60);
}
```

### Dashboard Controller

```php
public function index(StreamService $stream): View
{
    return view('dashboard', [
        'stats' => [
            'transcoded_videos' => Video::where('transcoded', true)->count(),
            'pending_transcode' => Video::where('transcoded', false)->count(),
            'total_segments' => Video::sum('segment_count'),
            'stream_duration' => Video::sum('hls_duration'),
        ],
        'nowPlaying' => $stream->getNowPlaying(),
    ]);
}
```

### Dashboard View

```blade
<div class="rounded-xl border p-4">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold">Live Stream</h2>
        @if($nowPlaying)
            <span class="flex items-center gap-2 rounded-full bg-red-100 px-3 py-1 text-sm text-red-700">
                <span class="h-2 w-2 animate-pulse rounded-full bg-red-500"></span>
                Live
            </span>
        @endif
    </div>

    @if($nowPlaying)
        <div class="flex gap-4">
            <img src="{{ $nowPlaying->thumbnail_url }}" class="w-48 rounded-lg" />
            <div class="flex-1">
                <p class="font-medium">{{ $nowPlaying->video_title }}</p>
                <p class="text-sm text-neutral-500">
                    {{ $nowPlaying->position }} / {{ $nowPlaying->duration }}
                </p>
                <div class="mt-2 h-2 w-full rounded-full bg-neutral-200">
                    <div class="h-full rounded-full bg-blue-600"
                         style="width: {{ $nowPlaying->progress_percent }}%"></div>
                </div>
                <code class="mt-3 block rounded bg-neutral-100 px-2 py-1 text-sm">
                    {{ url('/stream/playlist.m3u8') }}
                </code>
            </div>
        </div>
    @else
        <p class="text-neutral-500">No videos ready for streaming.</p>
    @endif
</div>

<div class="grid gap-4 md:grid-cols-2 mt-4">
    <div class="rounded-xl border p-4">
        <h3 class="text-sm text-neutral-500">Stream Duration</h3>
        <p class="text-xl font-bold">{{ gmdate('H:i:s', $stats['stream_duration']) }}</p>
    </div>
    <div class="rounded-xl border p-4">
        <h3 class="text-sm text-neutral-500">Total Segments</h3>
        <p class="text-xl font-bold">{{ number_format($stats['total_segments']) }}</p>
    </div>
</div>
```

## Why This Works

**Synchronized viewing.** The deterministic schedule (seeded shuffle + time-based position) ensures all viewers see the same content. Late joiners synchronize immediately.

**No encoding pressure.** Transcoding happens ahead of time. Playback is just serving static files.

**Seamless transitions.** The `#EXT-X-DISCONTINUITY` tag handles video boundaries gracefully.

**Scalable.** The same segments serve unlimited viewers. Add a CDN for large audiences.

## When to Consider Alternatives

**Large audiences** need a CDN in front of your origin server.

**Low latency** requirements (live sports, auctions) need LL-HLS or WebRTC. Standard HLS has 10-30 seconds of latency.

**User-specific content** (video on demand with seeking) is a different architecture entirely.

## Conclusion

Pre-transcode once, generate playlists dynamically. The result is a 24/7 broadcast that all viewers experience together, running on a standard Laravel deployment.

Your video library becomes a TV channel.
