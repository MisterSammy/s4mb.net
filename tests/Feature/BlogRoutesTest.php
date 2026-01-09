<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
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

it('displays the home page with posts', function () {
    $content = <<<'MARKDOWN'
---
title: "Test Post"
date: 2025-01-15
slug: test-post
---

# Content
MARKDOWN;

    File::put(storage_path('posts/test-post.md'), $content);

    $response = $this->get('/');

    $response->assertStatus(200)
        ->assertSee('Test Post');
});

it('displays a single post page', function () {
    $content = <<<'MARKDOWN'
---
title: "My Test Post"
date: 2025-01-15
slug: my-test-post
---

# My Content
MARKDOWN;

    File::put(storage_path('posts/my-test-post.md'), $content);

    $response = $this->get('/posts/my-test-post');

    $response->assertStatus(200)
        ->assertSee('My Test Post')
        ->assertSee('My Content');
});

it('returns 404 for non-existent post', function () {
    $response = $this->get('/posts/non-existent-post');

    $response->assertNotFound();
});

it('blocks path traversal attempts', function () {
    $response = $this->get('/posts/../../../etc/passwd');

    $response->assertNotFound();
});

it('blocks invalid slug characters', function () {
    $response = $this->get('/posts/test_post_with_underscores');

    $response->assertNotFound();
});

it('allows valid slug format', function () {
    $content = <<<'MARKDOWN'
---
title: "Valid Post"
date: 2025-01-15
slug: valid-post-123
---

# Content
MARKDOWN;

    File::put(storage_path('posts/valid-post-123.md'), $content);

    $response = $this->get('/posts/valid-post-123');

    $response->assertStatus(200)
        ->assertSee('Valid Post');
});

it('switches theme preference', function () {
    $response = $this->post('/theme/switch', [
        'theme' => 'pixel-dark',
    ]);

    $response->assertRedirect();
    $this->assertTrue(session('theme_preference') === 'pixel-dark');
});

it('validates theme exists when switching', function () {
    $response = $this->post('/theme/switch', [
        'theme' => 'non-existent-theme',
    ]);

    $response->assertSessionHasErrors('theme');
});
