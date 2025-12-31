<?php

namespace App\View\Composers;

use App\Services\ThemeService;
use Illuminate\View\View;

class ThemeComposer
{
    public function __construct(
        protected ThemeService $themeService
    ) {}

    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        $view->with('themeCss', $this->themeService->getCssVariables());
        $view->with('currentThemeSlug', $this->themeService->getCurrentThemeSlug());
        $view->with('hasExplicitPreference', \Illuminate\Support\Facades\Session::get('theme_preference_explicit', false));
    }
}
