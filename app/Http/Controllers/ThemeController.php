<?php

namespace App\Http\Controllers;

use App\Services\ThemeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    public function __construct(
        protected ThemeService $themeService
    ) {}

    /**
     * Switch the user's theme preference.
     */
    public function switch(Request $request): RedirectResponse
    {
        $allowedThemes = array_keys(config('themes.themes', []));

        $request->validate([
            'theme' => ['required', 'string', 'in:'.implode(',', $allowedThemes)],
        ]);

        $themeSlug = $request->input('theme');
        $this->themeService->setThemePreference($themeSlug, true);

        return redirect()->back();
    }

    /**
     * Set the system theme preference (from prefers-color-scheme).
     */
    public function setSystemPreference(Request $request): \Illuminate\Http\JsonResponse
    {
        $allowedThemes = array_keys(config('themes.themes', []));

        $request->validate([
            'theme' => ['required', 'string', 'in:'.implode(',', $allowedThemes)],
        ]);

        $themeSlug = $request->input('theme');
        $this->themeService->setSystemPreference($themeSlug);

        return response()->json(['success' => true]);
    }
}
