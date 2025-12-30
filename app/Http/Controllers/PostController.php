<?php

namespace App\Http\Controllers;

use App\Models\Post;

class PostController extends Controller
{
    /**
     * Display the specified post.
     */
    public function show(Post $post)
    {
        $post->load('images');

        return view('post', [
            'post' => $post,
        ]);
    }
}
