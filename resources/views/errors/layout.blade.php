<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="@yield('description', 'An error occurred.')">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="dns-prefetch" href="https://fonts.gstatic.com">
        <title>@yield('title') — {{ config('app.name', 'Sam') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @if(isset($hasExplicitPreference) && $hasExplicitPreference && isset($themeCss))
        <style>{!! $themeCss !!}</style>
        @else
        {{-- Default to dark theme for error pages when session is unavailable --}}
        <style>
        :root {
            --color-background: #11130f;
            --color-surface: #1a2330;
            --color-accent: #0b8b7f;
            --color-secondary-accent: #5eb8ad;
            --color-text: #d4d9c4;
            --color-text-muted: #7a9088;
            --color-border: #2a3542;
            --color-darkest: #e9ecb1;
        }
        </style>
        @endif
    </head>
    <body class="bg-[var(--color-background)] text-[var(--color-text)] min-h-screen font-sans antialiased overflow-x-hidden">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:px-4 focus:py-2 focus:bg-[var(--color-accent)] focus:text-[var(--color-background)] focus:font-mono focus:text-sm">
            Skip to main content
        </a>

        {{-- Subtle dot grid background --}}
        <div class="fixed inset-0 pointer-events-none dot-matrix" style="--dot-gap: 40px; --dot-size: 1px;" aria-hidden="true"></div>

        <div class="relative min-h-screen flex flex-col">
            {{-- Header --}}
            <header class="border-b border-[var(--color-border)] relative z-10">
                <div class="max-w-5xl mx-auto px-6 lg:px-8">
                    <nav class="flex items-center justify-between h-16" aria-label="Primary navigation">
                        <div class="flex items-center gap-3">
                            @if($__env->yieldContent('show_home_link', true))
                            <a href="{{ url('/') }}" class="corner-brackets">
                            @else
                            <div class="corner-brackets">
                            @endif
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-[var(--color-surface)] to-[var(--color-border)] flex items-center justify-center">
                                    <span class="text-[var(--color-darkest)] text-sm font-medium">S</span>
                                </div>
                            @if($__env->yieldContent('show_home_link', true))
                            </a>
                            @else
                            </div>
                            @endif
                        </div>

                        @hasSection('header_nav')
                            @yield('header_nav')
                        @else
                        <div class="flex items-center gap-8">
                            <a href="{{ url('/') }}#posts" class="font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors hover-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2">
                                Posts
                            </a>
                            <span class="text-[var(--color-border)]" aria-hidden="true">—</span>
                            <a href="#" class="font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors hover-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2" aria-disabled="true">
                                Elsewhere
                            </a>
                            <div class="corner-brackets ml-2" aria-hidden="true">
                                <span class="text-xs text-[var(--color-text-muted)] font-mono">↗</span>
                            </div>
                        </div>
                        @endif
                    </nav>
                </div>
            </header>

            {{-- Error Content --}}
            <main id="main-content" class="flex-1 flex items-center justify-center py-16 relative overflow-hidden">
                @yield('decorations')

                <div class="max-w-5xl mx-auto px-6 lg:px-8 relative z-10">
                    <div class="text-center">
                        {{-- Error Label --}}
                        <div class="flex items-center justify-center gap-4 mb-8">
                            <span class="font-mono text-xs text-[var(--color-text-muted)] tracking-widest">@yield('label', 'ERROR')</span>
                            <div class="flex gap-1" aria-hidden="true">
                                @for($i = 0; $i < ($dots ?? 4); $i++)
                                    <span class="w-1.5 h-1.5 rounded-full {{ $i === 0 ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-border)]' }}"></span>
                                @endfor
                            </div>
                        </div>

                        <h1 class="text-8xl md:text-9xl font-light text-[var(--color-darkest)] tracking-tight mb-4">
                            @yield('code')
                        </h1>

                        <p class="text-xl md:text-2xl text-[var(--color-text-muted)] leading-relaxed mb-8 max-w-lg mx-auto">
                            @yield('message')
                        </p>

                        {{-- Dog SVG --}}
                        <div class="flex justify-center mb-12" aria-hidden="true">
                            <div class="dashed-circle w-48 h-48 md:w-64 md:h-64 flex items-center justify-center p-8 animate-float">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="w-full h-full text-[var(--color-text-muted)]" role="img" aria-hidden="true">
                                    <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1">
                                        <line x1="25.129" y1="17" x2="22.181" y2="19.948"/>
                                        <line x1="22.181" y1="17" x2="25.129" y2="19.948"/>
                                        <circle cx="34.5" cy="10.5" r="2.5"/>
                                        <circle cx="14" cy="10" r="1" fill="currentColor" stroke="none"/>
                                        <line x1="14.861" y1="48.722" x2="12.75" y2="44.5"/>
                                        <line x1="10.288" y1="46.146" x2="11.875" y2="48.792"/>
                                        <path d="M13.437,30.379l-5.988.367A3.722,3.722,0,0,0,4,34.458H4A3.722,3.722,0,0,1,2.91,37.09L1,39"/>
                                        <path d="M44,49h8.469A7.531,7.531,0,0,1,60,56.531V61"/>
                                        <line x1="11.53" y1="49" x2="40.23" y2="49"/>
                                        <path d="M1,61V56.531a7.532,7.532,0,0,1,7-7.513"/>
                                        <polyline points="51.861 48.722 51 47 49 46 49.583 42.5"/>
                                        <polyline points="46.4 44 46 46 46.907 48.722"/>
                                        <path d="M44.412,23.774,42,29s-20-2-28,1c-6.621,2.483-4,8-5,10a47.085,47.085,0,0,0-2,5l2,6h3l-2-5a32.25,32.25,0,0,0,7.288-5.672C23.3,37.51,25.769,41.192,37,44a19.757,19.757,0,0,0,3.855.581C40.642,45.43,40,48,40,48l2,4h4l-2-2-1-3,1.819-2.729a12.24,12.24,0,0,0,6.9-4.292,6.4,6.4,0,0,0,1.4-3.532L54,25h5.149a3,3,0,0,0,2.679-1.656L63,21l-6-3-1-2H51"/>
                                        <path d="M44,16h0a7,7,0,0,1,7,7v2.387A2.613,2.613,0,0,1,48.387,28H44.8a.8.8,0,0,1-.8-.8V16A0,0,0,0,1,44,16Z" transform="translate(95 44) rotate(180)"/>
                                        <circle cx="55" cy="19" r="1" fill="currentColor" stroke="none"/>
                                        <line x1="63" y1="21" x2="61" y2="21"/>
                                        <circle cx="54" cy="56" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="51" cy="53" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="48" cy="58" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="31" cy="58" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="25" cy="59" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="41" cy="57" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="36" cy="59" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="19" cy="53" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="35" cy="54" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="10" cy="59" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="24" cy="55" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="15" cy="56" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="19" cy="58" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="30" cy="53" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="8" cy="55" r="1" fill="currentColor" stroke="none"/>
                                        <path d="M19,35s1.454,1.777-1.71,5.33"/>
                                    </g>
                                </svg>
                            </div>
                        </div>

                        {{-- Decorative line with dot --}}
                        <div class="flex items-center justify-center gap-4 mb-8" aria-hidden="true">
                            <div class="line-dot">
                                <span class="dot"></span>
                            </div>
                        </div>

                        {{-- Action --}}
                        <div class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2">
                            @yield('action')
                        </div>
                    </div>
                </div>
            </main>

            {{-- Footer --}}
            <footer class="border-t border-[var(--color-border)] py-12 relative">
                <div class="max-w-5xl mx-auto px-6 lg:px-8 relative z-10">
                    @hasSection('footer')
                        @yield('footer')
                    @else
                    <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                        <div class="flex items-center gap-6">
                            <a href="#" class="font-mono text-xs text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2" aria-disabled="true">GitHub</a>
                            <span class="w-1 h-1 rounded-full bg-[var(--color-border)]" aria-hidden="true"></span>
                            <a href="#" class="font-mono text-xs text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2" aria-disabled="true">Twitter</a>
                            <span class="w-1 h-1 rounded-full bg-[var(--color-border)]" aria-hidden="true"></span>
                            <a href="#" class="font-mono text-xs text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2" aria-disabled="true">LinkedIn</a>
                        </div>
                        
                        <div class="flex items-center gap-4">
                            <div class="flex gap-1.5" aria-hidden="true">
                                @for($i = 0; $i < 3; $i++)
                                    <span class="w-1 h-1 rounded-full bg-[var(--color-border)]"></span>
                                @endfor
                            </div>
                            <p class="font-mono text-xs text-[var(--color-text-muted)]">
                                © {{ date('Y') }} {{ config('app.name', 'Sam') }}
                            </p>
                        </div>
                    </div>
                    @endif
                </div>
            </footer>
        </div>
    </body>
</html>

