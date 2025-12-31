<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Theme extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'colors',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'colors' => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Theme $theme) {
            if (empty($theme->slug)) {
                $theme->slug = Str::slug($theme->name);
            }
        });

        static::saved(function (Theme $theme) {
            Cache::forget('active_theme');
        });

        static::deleted(function (Theme $theme) {
            Cache::forget('active_theme');
        });
    }

    /**
     * Get the active theme.
     */
    public static function active(): ?self
    {
        return Cache::remember('active_theme', 3600, function () {
            return static::where('is_active', true)->first();
        });
    }

    /**
     * Activate this theme and deactivate others.
     */
    public function activate(): void
    {
        static::where('is_active', true)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
        Cache::forget('active_theme');
    }

    /**
     * Get a specific color from the theme.
     */
    public function getColor(string $key, string $default = '#000000'): string
    {
        return $this->colors[$key] ?? $default;
    }

    /**
     * Generate CSS variables string from theme colors.
     */
    public function toCssVariables(): string
    {
        $colors = $this->colors ?? [];

        $variables = [
            '--color-background' => $colors['background'] ?? '#fbf5ef',
            '--color-surface' => $colors['surface'] ?? '#f2d3ab',
            '--color-accent' => $colors['accent'] ?? '#c69fa5',
            '--color-secondary-accent' => $colors['secondary_accent'] ?? '#8b6d9c',
            '--color-text' => $colors['text'] ?? '#494d7e',
            '--color-text-muted' => $colors['text_muted'] ?? '#6b6f9e',
            '--color-border' => $colors['border'] ?? '#d4c4b0',
            '--color-darkest' => $colors['darkest'] ?? '#272744',
        ];

        $css = ':root {';
        foreach ($variables as $property => $value) {
            $css .= "{$property}: {$value};";
        }
        $css .= '}';

        return $css;
    }

    /**
     * Get the default color values.
     *
     * @return array<string, string>
     */
    public static function defaultColors(): array
    {
        return [
            'background' => '#fbf5ef',
            'surface' => '#f2d3ab',
            'accent' => '#c69fa5',
            'secondary_accent' => '#8b6d9c',
            'text' => '#494d7e',
            'text_muted' => '#6b6f9e',
            'border' => '#d4c4b0',
            'darkest' => '#272744',
        ];
    }
}
