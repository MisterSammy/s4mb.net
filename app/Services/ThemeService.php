<?php

namespace App\Services;

use App\Models\Theme;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

class ThemeService
{
    /**
     * Cache key for the active theme.
     */
    private const CACHE_KEY = 'active_theme';

    /**
     * Cache TTL in seconds (1 hour).
     */
    private const CACHE_TTL = 3600;

    /**
     * Session key for user's theme preference.
     */
    private const SESSION_KEY = 'theme_preference';

    /**
     * Session key for whether user has explicitly set a preference.
     */
    private const SESSION_EXPLICIT_KEY = 'theme_preference_explicit';

    /**
     * Get the currently active theme.
     * Checks session preference first, then system preference, then falls back to globally active theme.
     */
    public function getActiveTheme(): ?Theme
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

        // Fall back to globally active theme
        return Theme::active();
    }

    /**
     * Get a theme by its slug.
     */
    public function getThemeBySlug(string $slug): ?Theme
    {
        return Theme::where('slug', $slug)->first();
    }

    /**
     * Generate CSS variables for the active theme.
     */
    public function getCssVariables(): string
    {
        $theme = $this->getActiveTheme();

        if ($theme) {
            return $theme->toCssVariables();
        }

        return $this->getDefaultCssVariables();
    }

    /**
     * Get default CSS variables when no theme is active.
     */
    public function getDefaultCssVariables(): string
    {
        $colors = Theme::defaultColors();

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
     * Clear the theme cache.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
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
            return $theme->colors ?? Theme::defaultColors();
        }

        return Theme::defaultColors();
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
     * Get the current theme slug (from session, system preference, or active theme).
     */
    public function getCurrentThemeSlug(): ?string
    {
        $sessionThemeSlug = Session::get(self::SESSION_KEY);
        $hasExplicitPreference = Session::get(self::SESSION_EXPLICIT_KEY, false);

        if ($sessionThemeSlug && $hasExplicitPreference) {
            $theme = $this->getThemeBySlug($sessionThemeSlug);
            if ($theme) {
                return $theme->slug;
            }
        }

        // If no explicit preference, check system preference
        if (! $hasExplicitPreference) {
            $systemPreference = Session::get('system_theme_preference');
            if ($systemPreference) {
                $theme = $this->getThemeBySlug($systemPreference);
                if ($theme) {
                    return $theme->slug;
                }
            }
        }

        $activeTheme = Theme::active();

        return $activeTheme?->slug;
    }
}
