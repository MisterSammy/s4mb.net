<?php

use App\Data\Post;
use Carbon\Carbon;

it('returns explicit excerpt when provided', function () {
    $post = new Post(
        title: 'Test Post',
        slug: 'test-post',
        content: 'This is a very long content that would normally be truncated...',
        excerpt: 'Custom excerpt',
        date: Carbon::now(),
        tags: []
    );

    expect($post->getDisplayExcerpt())->toBe('Custom excerpt');
});

it('generates excerpt from content when not provided', function () {
    $content = 'This is a long piece of content that should be truncated to 250 characters. '.str_repeat('Lorem ipsum dolor sit amet. ', 20);

    $post = new Post(
        title: 'Test Post',
        slug: 'test-post',
        content: $content,
        excerpt: null,
        date: Carbon::now(),
        tags: []
    );

    $excerpt = $post->getDisplayExcerpt();

    expect($excerpt)->not->toBeEmpty()
        ->and(strlen($excerpt))->toBeLessThanOrEqual(253) // 250 + "..."
        ->and($excerpt)->toContain('This is a long');
});

it('truncates excerpt to 250 characters', function () {
    $longContent = str_repeat('A', 500);

    $post = new Post(
        title: 'Test Post',
        slug: 'test-post',
        content: $longContent,
        excerpt: null,
        date: Carbon::now(),
        tags: []
    );

    $excerpt = $post->getDisplayExcerpt();

    expect(strlen($excerpt))->toBeLessThanOrEqual(253);
});

it('handles empty excerpt and empty content', function () {
    $post = new Post(
        title: 'Test Post',
        slug: 'test-post',
        content: '',
        excerpt: null,
        date: Carbon::now(),
        tags: []
    );

    $excerpt = $post->getDisplayExcerpt();

    expect($excerpt)->toBe('');
});

it('strips markdown formatting from generated excerpt', function () {
    $content = <<<'MARKDOWN'
# Heading

This is **bold** text and *italic* text.

## Subheading

More content here.
MARKDOWN;

    $post = new Post(
        title: 'Test Post',
        slug: 'test-post',
        content: $content,
        excerpt: null,
        date: Carbon::now(),
        tags: []
    );

    $excerpt = $post->getDisplayExcerpt();

    expect($excerpt)->not->toContain('#')
        ->and($excerpt)->not->toContain('**')
        ->and($excerpt)->not->toContain('*');
});
