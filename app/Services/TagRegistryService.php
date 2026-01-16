<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TagRegistryService
{
    private ?array $registry = null;

    public function getRegistry(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $registryPath = storage_path('app/tags-registry.json');

        if (! File::exists($registryPath)) {
            return $this->registry = $this->getEmptyRegistry();
        }

        $cacheKey = 'tags.registry.'.File::lastModified($registryPath);

        $this->registry = Cache::remember($cacheKey, 3600, function () use ($registryPath) {
            $content = File::get($registryPath);
            $data = json_decode($content, true);

            return $data ?? $this->getEmptyRegistry();
        });

        return $this->registry;
    }

    public function getAllTags(): Collection
    {
        $registry = $this->getRegistry();

        return collect($registry['tags'] ?? [])
            ->sortByDesc('count')
            ->values();
    }

    public function getTagIcon(string $slug): string
    {
        $registry = $this->getRegistry();
        $slug = Str::slug($slug);

        // Find the icon key for this tag
        $tag = $registry['tags'][$slug] ?? null;
        $iconKey = $tag['icon'] ?? config('tags.default_icon', 'tag');

        // Return the SVG from registry or fall back to config
        return $registry['icons'][$iconKey]
            ?? config("tags.icons.{$iconKey}")
            ?? config('tags.icons.'.config('tags.default_icon', 'tag'))
            ?? '';
    }

    public function getTag(string $slug): ?array
    {
        $registry = $this->getRegistry();
        $slug = Str::slug($slug);

        return $registry['tags'][$slug] ?? null;
    }

    public function hasTag(string $slug): bool
    {
        return $this->getTag($slug) !== null;
    }

    private function getEmptyRegistry(): array
    {
        return [
            'generated_at' => null,
            'tags' => [],
            'icons' => [],
        ];
    }
}
