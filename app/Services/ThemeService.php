<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;

class ThemeService
{
    /**
     * Session key for user's theme preference.
     */
    private const SESSION_KEY = 'theme_preference';

    /**
     * Session key for whether user has explicitly set a preference.
     */
    private const SESSION_EXPLICIT_KEY = 'theme_preference_explicit';

    /**
     * Get the currently active theme data.
     * Checks session preference first, then system preference, then falls back to default theme.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveTheme(): ?array
    {
        // Check session for user's explicit theme preference
        $sessionThemeSlug = Session::get(self::SESSION_KEY);
        $hasExplicitPreference = Session::get(self::SESSION_EXPLICIT_KEY, false);

        if ($sessionThemeSlug && $hasExplicitPreference) {
            $theme = $this->getThemeBySlug($sessionThemeSlug);
            if ($theme) {
                return $theme;
            }
        }

        // If no explicit preference, check for system preference
        if (! $hasExplicitPreference) {
            $systemPreference = Session::get('system_theme_preference');
            if ($systemPreference) {
                $theme = $this->getThemeBySlug($systemPreference);
                if ($theme) {
                    return $theme;
                }
            }
        }

        // Fall back to default theme
        return $this->getDefaultTheme();
    }

    /**
     * Get a theme by its slug.
     *
     * @return array<string, mixed>|null
     */
    public function getThemeBySlug(string $slug): ?array
    {
        $themes = config('themes.themes', []);

        if (isset($themes[$slug])) {
            return array_merge(['slug' => $slug], $themes[$slug]);
        }

        return null;
    }

    /**
     * Get the default theme.
     *
     * @return array<string, mixed>|null
     */
    public function getDefaultTheme(): ?array
    {
        $defaultSlug = config('themes.default', 'pixel-cream');

        return $this->getThemeBySlug($defaultSlug);
    }

    /**
     * Generate CSS variables for the active theme.
     */
    public function getCssVariables(): string
    {
        $theme = $this->getActiveTheme();

        if ($theme) {
            return $this->themeToCssVariables($theme);
        }

        return $this->getDefaultCssVariables();
    }

    /**
     * Get default CSS variables when no theme is active.
     */
    public function getDefaultCssVariables(): string
    {
        $colors = $this->getDefaultColors();

        $css = ':root {';
        $css .= "--color-background: {$colors['background']};";
        $css .= "--color-surface: {$colors['surface']};";
        $css .= "--color-accent: {$colors['accent']};";
        $css .= "--color-secondary-accent: {$colors['secondary_accent']};";
        $css .= "--color-text: {$colors['text']};";
        $css .= "--color-text-muted: {$colors['text_muted']};";
        $css .= "--color-border: {$colors['border']};";
        $css .= "--color-darkest: {$colors['darkest']};";
        $css .= '}';

        return $css;
    }

    /**
     * Convert theme array to CSS variables string.
     *
     * @param  array<string, mixed>  $theme
     */
    private function themeToCssVariables(array $theme): string
    {
        $colors = $theme['colors'] ?? [];

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
     * Get the active theme's colors as an array.
     *
     * @return array<string, string>
     */
    public function getColors(): array
    {
        $theme = $this->getActiveTheme();

        if ($theme) {
            return $theme['colors'] ?? $this->getDefaultColors();
        }

        return $this->getDefaultColors();
    }

    /**
     * Get default color values.
     *
     * @return array<string, string>
     */
    public function getDefaultColors(): array
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

    /**
     * Set the user's theme preference in session.
     */
    public function setThemePreference(string $slug, bool $explicit = true): void
    {
        Session::put(self::SESSION_KEY, $slug);
        Session::put(self::SESSION_EXPLICIT_KEY, $explicit);
    }

    /**
     * Set the system theme preference (from prefers-color-scheme).
     */
    public function setSystemPreference(string $slug): void
    {
        // Only set if user hasn't explicitly chosen a theme
        if (! Session::get(self::SESSION_EXPLICIT_KEY, false)) {
            Session::put('system_theme_preference', $slug);
        }
    }

    /**
     * Get the current theme slug (from session, system preference, or default theme).
     */
    public function getCurrentThemeSlug(): ?string
    {
        $sessionThemeSlug = Session::get(self::SESSION_KEY);
        $hasExplicitPreference = Session::get(self::SESSION_EXPLICIT_KEY, false);

        if ($sessionThemeSlug && $hasExplicitPreference) {
            $theme = $this->getThemeBySlug($sessionThemeSlug);
            if ($theme) {
                return $theme['slug'];
            }
        }

        // If no explicit preference, check system preference
        if (! $hasExplicitPreference) {
            $systemPreference = Session::get('system_theme_preference');
            if ($systemPreference) {
                $theme = $this->getThemeBySlug($systemPreference);
                if ($theme) {
                    return $theme['slug'];
                }
            }
        }

        $defaultTheme = $this->getDefaultTheme();

        return $defaultTheme['slug'] ?? null;
    }
}
