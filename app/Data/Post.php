<?php

namespace App\Data;

use Carbon\Carbon;
use Illuminate\Support\Str;

class Post
{
    public function __construct(
        public string $title,
        public string $slug,
        public string $content,
        public ?string $excerpt,
        public Carbon $date,
        public array $tags,
    ) {}

    /**
     * Get the display excerpt, auto-generating if empty.
     */
    public function getDisplayExcerpt(): string
    {
        if (! empty($this->excerpt)) {
            return $this->excerpt;
        }

        // Auto-generate excerpt from content (first 250 characters, stripped of markdown)
        $plainText = strip_tags(str($this->content)->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false]));

        return Str::limit($plainText, 250);
    }
}
