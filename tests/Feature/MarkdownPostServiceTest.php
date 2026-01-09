<?php

use App\Data\Post;
use App\Services\MarkdownPostService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->service = app(MarkdownPostService::class);
    $this->postsPath = storage_path('posts');

    // Ensure posts directory exists
    if (! File::exists($this->postsPath)) {
        File::makeDirectory($this->postsPath, 0755, true);
    }
});

afterEach(function () {
    // Clean up test files
    $files = File::glob($this->postsPath.'/test-*.md');
    foreach ($files as $file) {
        File::delete($file);
    }
});

it('parses valid frontmatter correctly', function () {
    $content = <<<'MARKDOWN'
---
title: "Test Post"
date: 2025-01-15
excerpt: "A test excerpt"
tags: [laravel, php]
slug: test-post
---

# Content
MARKDOWN;

    File::put($this->postsPath.'/test-post.md', $content);

    $post = $this->service->findBySlug('test-post');

    expect($post)->toBeInstanceOf(Post::class)
        ->and($post->title)->toBe('Test Post')
        ->and($post->slug)->toBe('test-post')
        ->and($post->excerpt)->toBe('A test excerpt')
        ->and($post->tags)->toBe(['laravel', 'php'])
        ->and($post->date->format('Y-m-d'))->toBe('2025-01-15');
});

it('returns null when title is missing', function () {
    $content = <<<'MARKDOWN'
---
date: 2025-01-15
---

# Content
MARKDOWN;

    File::put($this->postsPath.'/test-no-title.md', $content);

    $post = $this->service->findBySlug('test-no-title');

    expect($post)->toBeNull();
});

it('auto-generates slug from title when not provided', function () {
    $content = <<<'MARKDOWN'
---
title: "My Awesome Post"
date: 2025-01-15
---

# Content
MARKDOWN;

    File::put($this->postsPath.'/my-awesome-post.md', $content);

    $post = $this->service->findBySlug('my-awesome-post');

    expect($post)->toBeInstanceOf(Post::class)
        ->and($post->slug)->not->toBeEmpty();
});

it('falls back to file modification time when date is missing', function () {
    $content = <<<'MARKDOWN'
---
title: "Test Post"
---

# Content
MARKDOWN;

    $filePath = $this->postsPath.'/test-no-date.md';
    File::put($filePath, $content);

    $post = $this->service->findBySlug('test-no-date');

    expect($post)->toBeInstanceOf(Post::class)
        ->and($post->date)->toBeInstanceOf(\Carbon\Carbon::class);
});

it('handles invalid date gracefully', function () {
    $content = <<<'MARKDOWN'
---
title: "Test Post"
date: "invalid-date"
---

# Content
MARKDOWN;

    $filePath = $this->postsPath.'/test-invalid-date.md';
    File::put($filePath, $content);

    $post = $this->service->findBySlug('test-invalid-date');

    expect($post)->toBeInstanceOf(Post::class)
        ->and($post->date)->toBeInstanceOf(\Carbon\Carbon::class);
});

it('extracts markdown content correctly', function () {
    $content = <<<'MARKDOWN'
---
title: "Test Post"
---

# Heading

This is the content.
MARKDOWN;

    File::put($this->postsPath.'/test-content.md', $content);

    $post = $this->service->findBySlug('test-content');

    expect($post)->toBeInstanceOf(Post::class)
        ->and($post->content)->toContain('# Heading')
        ->and($post->content)->toContain('This is the content')
        ->and($post->content)->not->toContain('---');
});

it('extracts h2 headings correctly', function () {
    $markdown = <<<'MARKDOWN'
# Main Heading

## First Section
Content here.

## Second Section
More content.

## Third Section
Even more.
MARKDOWN;

    $headings = $this->service->extractHeadings($markdown);

    expect($headings)->toHaveCount(3)
        ->and($headings->first()['text'])->toBe('First Section')
        ->and($headings->first()['slug'])->toBe('first-section')
        ->and($headings->get(1)['text'])->toBe('Second Section')
        ->and($headings->get(2)['text'])->toBe('Third Section');
});

it('renders markdown with anchor IDs for h2 elements', function () {
    $markdown = <<<'MARKDOWN'
## My Heading

Content here.
MARKDOWN;

    $html = $this->service->renderMarkdownWithIds($markdown);

    expect($html)->toContain('<h2')
        ->and($html)->toContain('id="my-heading"');
});

it('returns all posts sorted by date newest first', function () {
    // Create posts with different dates
    $oldPost = <<<'MARKDOWN'
---
title: "Old Post"
date: 2025-01-01
slug: old-post
---
MARKDOWN;

    $newPost = <<<'MARKDOWN'
---
title: "New Post"
date: 2025-01-15
slug: new-post
---
MARKDOWN;

    File::put($this->postsPath.'/old-post.md', $oldPost);
    File::put($this->postsPath.'/new-post.md', $newPost);

    $posts = $this->service->getAllPosts();

    expect($posts)->toHaveCount(2)
        ->and($posts->first()->slug)->toBe('new-post')
        ->and($posts->last()->slug)->toBe('old-post');
});

it('returns null for invalid slug format', function () {
    $post = $this->service->findBySlug('../../../etc/passwd');

    expect($post)->toBeNull();
});

it('returns null for non-existent slug', function () {
    $post = $this->service->findBySlug('non-existent-post');

    expect($post)->toBeNull();
});

it('handles array tags correctly', function () {
    $content = <<<'MARKDOWN'
---
title: "Test Post"
tags: [laravel, php, tutorial]
---

# Content
MARKDOWN;

    File::put($this->postsPath.'/test-tags.md', $content);

    $post = $this->service->findBySlug('test-tags');

    expect($post)->toBeInstanceOf(Post::class)
        ->and($post->tags)->toBe(['laravel', 'php', 'tutorial']);
});

it('handles empty tags array', function () {
    $content = <<<'MARKDOWN'
---
title: "Test Post"
tags: []
---

# Content
MARKDOWN;

    File::put($this->postsPath.'/test-no-tags.md', $content);

    $post = $this->service->findBySlug('test-no-tags');

    expect($post)->toBeInstanceOf(Post::class)
        ->and($post->tags)->toBe([]);
});
