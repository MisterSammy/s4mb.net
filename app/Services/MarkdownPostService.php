<?php

namespace App\Services;

use App\Data\Post;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MarkdownPostService
{
    /**
     * Get the posts directory path.
     */
    private function getPostsPath(): string
    {
        return storage_path('posts');
    }

    /**
     * Get all posts, sorted by date (newest first).
     *
     * @return Collection<int, Post>
     */
    public function getAllPosts(): Collection
    {
        $postsPath = $this->getPostsPath();

        if (! File::exists($postsPath)) {
            File::makeDirectory($postsPath, 0755, true);
        }

        $files = File::glob($postsPath.'/*.md');

        // Generate cache key based on file modification times for auto-invalidation
        $cacheKey = 'posts.all.'.collect($files)
            ->map(fn (string $file) => File::lastModified($file))
            ->sum();

        return Cache::remember($cacheKey, 3600, function () use ($files) {
            $posts = collect($files)
                ->map(fn (string $file) => $this->parsePostFile($file))
                ->filter()
                ->sortByDesc(fn (Post $post) => $post->date->timestamp);

            return $posts->values();
        });
    }

    /**
     * Find a post by slug.
     */
    public function findBySlug(string $slug): ?Post
    {
        // Sanitize slug - only allow lowercase letters, numbers, and hyphens
        // This matches the route constraint and prevents path traversal attacks
        if (! preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return null;
        }

        $postsPath = $this->getPostsPath();
        $filePath = $postsPath.'/'.$slug.'.md';

        if (! File::exists($filePath)) {
            return null;
        }

        return $this->parsePostFile($filePath);
    }

    /**
     * Parse a markdown post file.
     */
    private function parsePostFile(string $filePath): ?Post
    {
        if (! File::exists($filePath)) {
            return null;
        }

        $content = File::get($filePath);

        // Parse frontmatter
        $frontmatter = $this->parseFrontmatter($content);
        $markdownContent = $this->extractMarkdownContent($content);

        if (! $frontmatter) {
            return null;
        }

        // Extract required fields
        $title = $frontmatter['title'] ?? '';
        $slug = $frontmatter['slug'] ?? $this->generateSlugFromTitle($title);
        $date = $this->parseDate($frontmatter['date'] ?? null, $filePath);
        $excerpt = $frontmatter['excerpt'] ?? null;
        $tags = $frontmatter['tags'] ?? [];

        if (empty($title)) {
            return null;
        }

        return new Post(
            title: $title,
            slug: $slug,
            content: $markdownContent,
            excerpt: $excerpt,
            date: $date,
            tags: is_array($tags) ? $tags : [],
        );
    }

    /**
     * Parse YAML frontmatter from markdown content.
     *
     * @return array<string, mixed>|null
     */
    private function parseFrontmatter(string $content): ?array
    {
        // Check if content starts with frontmatter
        if (! str_starts_with($content, '---')) {
            return null;
        }

        // Find the end of frontmatter
        $endPos = strpos($content, "\n---", 3);
        if ($endPos === false) {
            return null;
        }

        $frontmatterText = substr($content, 3, $endPos - 3);

        return $this->parseSimpleYaml($frontmatterText);
    }

    /**
     * Simple YAML parser for frontmatter (handles basic key: value pairs).
     *
     * @return array<string, mixed>
     */
    private function parseSimpleYaml(string $yaml): array
    {
        $result = [];
        $lines = explode("\n", trim($yaml));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Handle key: value pairs
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*):\s*(.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);

                // Remove quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                // Handle arrays [item1, item2]
                if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                    $arrayContent = substr($value, 1, -1);
                    $items = array_map('trim', explode(',', $arrayContent));
                    $items = array_map(fn ($item) => trim($item, ' "\''), $items);
                    $result[$key] = array_filter($items);
                } else {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Extract markdown content (everything after frontmatter).
     */
    private function extractMarkdownContent(string $content): string
    {
        // Find the end of frontmatter
        $endPos = strpos($content, "\n---", 3);
        if ($endPos === false) {
            return $content;
        }

        // Get content after frontmatter delimiter
        $markdown = substr($content, $endPos + 4);
        $markdown = ltrim($markdown, "\n\r");

        return $markdown;
    }

    /**
     * Parse date from frontmatter or file modification time.
     */
    private function parseDate(mixed $dateValue, string $filePath): Carbon
    {
        if ($dateValue) {
            try {
                return Carbon::parse($dateValue);
            } catch (\Exception $e) {
                // Fall through to file modification time
            }
        }

        // Fall back to file modification time
        return Carbon::createFromTimestamp(File::lastModified($filePath));
    }

    /**
     * Generate a slug from title.
     */
    private function generateSlugFromTitle(string $title): string
    {
        $words = Str::words($title, 5, '');
        $slug = Str::slug($words);

        return $slug ?: 'untitled';
    }

    /**
     * Extract h2 headings from markdown content.
     *
     * @return Collection<int, array{text: string, slug: string}>
     */
    public function extractHeadings(string $markdownContent): Collection
    {
        $headings = collect();
        $lines = explode("\n", $markdownContent);

        foreach ($lines as $line) {
            // Match h2 headings (## Heading Text)
            if (preg_match('/^##\s+(.+)$/', trim($line), $matches)) {
                $text = trim($matches[1]);
                $slug = Str::slug($text);

                if (! empty($text) && ! empty($slug)) {
                    $headings->push([
                        'text' => $text,
                        'slug' => $slug,
                    ]);
                }
            }
        }

        return $headings;
    }

    /**
     * Process :::note ... ::: blocks into HTML alert divs.
     */
    protected function processAlertBlocks(string $content): string
    {
        // Match :::note ... ::: blocks (multiline)
        $pattern = '/:::note\s*\n(.*?)\n:::/s';

        return preg_replace_callback($pattern, function ($matches) {
            $text = trim($matches[1]);

            return '<div class="alert-note">'.$text.'</div>';
        }, $content);
    }

    /**
     * Render markdown content and inject anchor IDs into h2 elements.
     */
    public function renderMarkdownWithIds(string $markdownContent): string
    {
        // Process alert blocks before markdown rendering
        $markdownContent = $this->processAlertBlocks($markdownContent);

        // Render markdown with HTML allowed for our alert divs
        $html = str($markdownContent)->markdown([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        // Extract headings and create a mapping of text to slug
        $headings = $this->extractHeadings($markdownContent);

        if ($headings->isEmpty()) {
            return $html;
        }

        $headingMap = $headings->keyBy('text');

        // Use DOMDocument to parse and modify HTML
        // Wrap in a container div to handle HTML fragments properly
        $dom = new \DOMDocument;
        $previousValue = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="markdown-wrapper">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previousValue);

        // Find all h2 elements
        $h2Elements = $dom->getElementsByTagName('h2');

        // Process in reverse to avoid issues when modifying the DOM
        for ($i = $h2Elements->length - 1; $i >= 0; $i--) {
            $h2 = $h2Elements->item($i);
            $text = trim($h2->textContent);

            // Check if this heading text exists in our map
            if ($headingMap->has($text)) {
                $slug = $headingMap->get($text)['slug'];

                // Only add ID if it doesn't already exist
                if (! $h2->hasAttribute('id')) {
                    $h2->setAttribute('id', $slug);
                }
            }
        }

        // Extract the inner content of the wrapper div
        $wrapper = $dom->getElementById('markdown-wrapper');
        if ($wrapper) {
            $innerHtml = '';
            foreach ($wrapper->childNodes as $child) {
                $innerHtml .= $dom->saveHTML($child);
            }

            return $innerHtml;
        }

        // Fallback to full HTML if wrapper approach fails
        $html = $dom->saveHTML();
        $html = preg_replace('/^<\?xml encoding="UTF-8"\?>/', '', $html);

        return trim($html);
    }
}
