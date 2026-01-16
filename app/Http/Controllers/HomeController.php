<?php

namespace App\Http\Controllers;

use App\Services\MarkdownPostService;
use App\Services\TagRegistryService;

class HomeController extends Controller
{
    public function __construct(
        protected MarkdownPostService $postService,
        protected TagRegistryService $tagRegistry
    ) {}

    public function index()
    {
        $posts = $this->postService->getAllPosts();
        $tags = $this->tagRegistry->getAllTags();

        return view('home', [
            'posts' => $posts,
            'tags' => $tags,
        ]);
    }
}
