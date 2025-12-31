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
        $request->validate([
            'theme' => ['required', 'string'],
        ]);

        $themeSlug = $request->input('theme');
        $theme = $this->themeService->getThemeBySlug($themeSlug);

        if (! $theme) {
            return redirect()->back()->withErrors(['theme' => 'Theme not found.']);
        }

        $this->themeService->setThemePreference($themeSlug, true);

        return redirect()->back();
    }

    /**
     * Set the system theme preference (from prefers-color-scheme).
     */
    public function setSystemPreference(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'theme' => ['required', 'string'],
        ]);

        $themeSlug = $request->input('theme');
        $theme = $this->themeService->getThemeBySlug($themeSlug);

        if (! $theme) {
            return response()->json(['error' => 'Theme not found.'], 404);
        }

        $this->themeService->setSystemPreference($themeSlug);

        return response()->json(['success' => true]);
    }
}
