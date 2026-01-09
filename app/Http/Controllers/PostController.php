<?php

namespace App\Http\Controllers;

use App\Services\MarkdownPostService;

class PostController extends Controller
{
    public function __construct(
        protected MarkdownPostService $postService
    ) {}

    /**
     * Display the specified post.
     */
    public function show(string $slug)
    {
        $post = $this->postService->findBySlug($slug);

        if (! $post) {
            abort(404);
        }

        $headings = $this->postService->extractHeadings($post->content);
        $htmlContent = $this->postService->renderMarkdownWithIds($post->content);

        return view('post', [
            'post' => $post,
            'headings' => $headings,
            'htmlContent' => $htmlContent,
        ]);
    }
}
