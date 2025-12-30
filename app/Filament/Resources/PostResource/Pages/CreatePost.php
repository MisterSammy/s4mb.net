<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use App\Models\PostImage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelImageOptimizer\Facades\ImageOptimizer;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    protected function afterCreate(): void
    {
        $this->syncImagesFromMarkdown();
    }

    protected function syncImagesFromMarkdown(): void
    {
        $post = $this->record;
        $content = $post->content;

        // Extract image references from markdown
        // Pattern: ![alt text](path/to/image.jpg) or <img src="path/to/image.jpg" alt="alt text">
        preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)|<img[^>]+src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $index => $match) {
            // Handle markdown syntax: ![alt](path)
            if (! empty($match[2])) {
                $imagePath = $match[2];
                $altText = $match[1] ?? null;
            } else {
                // Handle HTML img tag
                $imagePath = $match[3] ?? null;
                $altText = $match[4] ?? null;
            }

            if (! $imagePath) {
                continue;
            }

            // Convert full URLs to relative paths
            if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
                // Check if it's a local URL (starts with app URL)
                $appUrl = config('app.url');
                if (str_starts_with($imagePath, $appUrl)) {
                    // Extract path from URL: http://localhost:8000/storage/posts/file.jpg -> /storage/posts/file.jpg
                    $imagePath = parse_url($imagePath, PHP_URL_PATH);
                } else {
                    // External URL, skip it
                    continue;
                }
            }

            // Convert storage URL path to relative path
            // /storage/posts/file.jpg -> posts/file.jpg
            $imagePath = ltrim($imagePath, '/');
            if (str_starts_with($imagePath, 'storage/')) {
                $imagePath = substr($imagePath, 8); // Remove 'storage/' prefix
            }

            // Remove storage URL prefix if present (for cases where it's already a relative path)
            $storageUrl = Storage::disk('public')->url('');
            if ($storageUrl && str_starts_with($imagePath, $storageUrl)) {
                $imagePath = substr($imagePath, strlen($storageUrl));
            }

            // Check if image exists in storage
            if (! Storage::disk('public')->exists($imagePath)) {
                continue;
            }

            $fullPath = Storage::disk('public')->path($imagePath);
            if (file_exists($fullPath)) {
                // Optimize the image
                ImageOptimizer::optimize($fullPath);
            }

            PostImage::create([
                'post_id' => $post->id,
                'path' => $imagePath,
                'alt_text' => $altText,
                'order' => $index,
            ]);
        }
    }
}
