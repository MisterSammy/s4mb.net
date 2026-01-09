<?php

namespace App\Http\Controllers;

use App\Services\MarkdownPostService;

class HomeController extends Controller
{
    public function __construct(
        protected MarkdownPostService $postService
    ) {}

    public function index()
    {
        $posts = $this->postService->getAllPosts();

        return view('home', [
            'posts' => $posts,
        ]);
    }
}
