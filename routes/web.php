<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ThemeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');
Route::post('/theme/switch', [ThemeController::class, 'switch'])->name('theme.switch');
Route::post('/theme/system-preference', [ThemeController::class, 'setSystemPreference'])->name('theme.system-preference');
