<?php

use App\Models\Post;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('title');
        });

        // Populate slugs for existing posts
        Post::chunk(100, function ($posts) {
            foreach ($posts as $post) {
                if (empty($post->slug)) {
                    $post->slug = $this->generateUniqueSlug($post->title);
                    $post->save();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    /**
     * Generate a unique slug from title.
     */
    private function generateUniqueSlug(string $title): string
    {
        $words = Str::words($title, 5, '');
        $slug = Str::slug($words);
        $originalSlug = $slug;
        $counter = 1;

        while (Post::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
};
