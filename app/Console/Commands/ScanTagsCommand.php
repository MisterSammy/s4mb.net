<?php

namespace App\Console\Commands;

use App\Services\MarkdownPostService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ScanTagsCommand extends Command
{
    protected $signature = 'tags:scan
                            {--dry-run : Preview the registry without writing to file}
                            {--show-unmapped : Show tags without explicit mappings}';

    protected $description = 'Scan all posts and generate the tags registry';

    public function __construct(
        protected MarkdownPostService $postService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Scanning posts for tags...');

        $posts = $this->postService->getAllPosts();
        $tagCounts = [];

        foreach ($posts as $post) {
            foreach ($post->tags as $tag) {
                $slug = Str::slug($tag);
                if (! isset($tagCounts[$slug])) {
                    $tagCounts[$slug] = 0;
                }
                $tagCounts[$slug]++;
            }
        }

        // Sort by count descending
        arsort($tagCounts);

        $this->info("Found {$posts->count()} posts with ".count($tagCounts).' unique tags.');

        // Build the registry
        $mappings = config('tags.mappings', []);
        $icons = config('tags.icons', []);
        $defaultIcon = config('tags.default_icon', 'tag');

        $tags = [];
        $unmappedTags = [];

        foreach ($tagCounts as $slug => $count) {
            if (isset($mappings[$slug])) {
                $mapping = $mappings[$slug];
                $iconKey = $mapping['icon'] ?? $defaultIcon;
                $tags[$slug] = [
                    'slug' => $slug,
                    'label' => $mapping['label'],
                    'count' => $count,
                    'icon' => $iconKey,
                    'color' => $mapping['color'] ?? null,
                ];
            } else {
                // Auto-generate for unmapped tags
                $unmappedTags[] = $slug;
                $tags[$slug] = [
                    'slug' => $slug,
                    'label' => Str::title(str_replace('-', ' ', $slug)),
                    'count' => $count,
                    'icon' => $defaultIcon,
                    'color' => null,
                ];
            }
        }

        // Build icons registry (only include icons that are actually used)
        $usedIcons = collect($tags)->pluck('icon')->unique()->toArray();
        $registryIcons = [];
        foreach ($usedIcons as $iconKey) {
            if (isset($icons[$iconKey])) {
                $registryIcons[$iconKey] = $icons[$iconKey];
            }
        }

        // Always include default icon
        if (isset($icons[$defaultIcon]) && ! isset($registryIcons[$defaultIcon])) {
            $registryIcons[$defaultIcon] = $icons[$defaultIcon];
        }

        $registry = [
            'generated_at' => now()->toIso8601String(),
            'tags' => $tags,
            'icons' => $registryIcons,
        ];

        // Display the tags table
        $tableData = [];
        foreach ($tags as $tag) {
            $tableData[] = [
                'slug' => $tag['slug'],
                'label' => $tag['label'],
                'count' => $tag['count'],
                'icon' => $tag['icon'],
                'mapped' => isset($mappings[$tag['slug']]) ? 'Yes' : 'No',
            ];
        }

        $this->table(['Slug', 'Label', 'Count', 'Icon', 'Mapped'], $tableData);

        // Show unmapped tags if requested
        if ($this->option('show-unmapped') && ! empty($unmappedTags)) {
            $this->newLine();
            $this->warn('Unmapped tags (using default icon):');
            foreach ($unmappedTags as $tag) {
                $this->line("  - {$tag}");
            }
            $this->newLine();
            $this->info('Add these to config/tags.php for custom icons.');
        }

        // Write or dry-run
        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry run - not writing to file.');
            $this->info('Registry would contain '.count($tags).' tags and '.count($registryIcons).' icons.');
        } else {
            $outputPath = storage_path('app/tags-registry.json');

            // Ensure directory exists
            File::ensureDirectoryExists(dirname($outputPath));

            File::put($outputPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->newLine();
            $this->info("Registry written to: {$outputPath}");
        }

        return self::SUCCESS;
    }
}
