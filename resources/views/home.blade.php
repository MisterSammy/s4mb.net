<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Developer and writer. Sharing thoughts on code, creativity, and building things that matter.">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="dns-prefetch" href="https://fonts.gstatic.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
        <title>{{ config('app.name', 'Sam') }} — Blog</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @if(isset($themeCss))
        <style>{!! $themeCss !!}</style>
        @endif
        @if(!$hasExplicitPreference)
        <script>
            // Detect system theme preference and send to server if not already set
            (function() {
                if (!sessionStorage.getItem('theme_preference_sent')) {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    const theme = prefersDark ? 'pixel-dark' : 'pixel-cream';
                    
                    fetch('{{ route("theme.system-preference") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({ theme: theme })
                    }).then(() => {
                        sessionStorage.setItem('theme_preference_sent', 'true');
                        // Reload to apply theme
                        window.location.reload();
                    }).catch(() => {
                        // Silently fail
                    });
                }
            })();
        </script>
        @endif
    </head>
    <body class="bg-[var(--color-background)] text-[var(--color-text)] min-h-screen font-sans antialiased overflow-x-hidden">
        {{-- Subtle pixel grid background --}}
        <div class="fixed inset-0 pointer-events-none"></div>

        <div class="relative min-h-screen">
            {{-- Header --}}
            <header class="border-b border-[var(--color-border)] relative z-10">
                <div class="max-w-5xl mx-auto px-6 lg:px-8">
                    <nav class="flex items-center justify-between h-16">
                        {{-- Logo/Avatar --}}
                        <div class="flex items-center gap-3">
                            <div class="corner-brackets">
                                <div class="w-8 h-8 bg-[var(--color-darkest)] flex items-center justify-center pixel-shadow">
                                    <span class="text-[var(--color-background)] text-sm font-medium">S</span>
                                </div>
                            </div>
                        </div>

                        {{-- Navigation --}}
                        <div class="flex items-center gap-8">
                            <a href="#posts" class="font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors hover-line">
                                Posts
                            </a>
                            <span class="text-[var(--color-border)]">—</span>
                            <a href="#" class="font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors hover-line">
                                Elsewhere
                            </a>
                            <div class="corner-brackets ml-2">
                                <span class="text-xs text-[var(--color-text-muted)] font-mono">↗</span>
                            </div>
                            <span class="text-[var(--color-border)]">—</span>
                            {{-- Theme Switcher --}}
                            <form action="{{ route('theme.switch') }}" method="POST" class="inline">
                                @csrf
                                <button type="submit" class="flex items-center justify-center w-5 h-5 text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors" aria-label="Toggle theme">
                                    <input type="hidden" name="theme" value="{{ ($currentThemeSlug ?? 'pixel-cream') === 'pixel-cream' ? 'pixel-dark' : 'pixel-cream' }}">
                                    @if(($currentThemeSlug ?? 'pixel-cream') === 'pixel-cream')
                                        {{-- Moon icon for dark mode --}}
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                                        </svg>
                                    @else
                                        {{-- Sun icon for light mode --}}
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                        </svg>
                                    @endif
                                </button>
                            </form>
                        </div>
                    </nav>
                </div>
            </header>

            {{-- Hero Section with Geometric Decorations --}}
            <section class="pt-24 pb-20 border-b border-[var(--color-border)] relative overflow-hidden">
                <div class="dot-matrix absolute inset-0" style="--dot-gap: 40px; --dot-size: 1px;"></div>
                {{-- Large orbital decoration --}}
                <div class="orbital absolute -right-48 top-1/2 -translate-y-1/2 animate-slow-spin" style="--orbital-size: 500px;">
                    <div class="orbital-ring absolute inset-[30%]"></div>
                    <div class="orbital-ring absolute inset-[45%] border-dashed"></div>
                    <div class="orbital-dot absolute top-1/2 right-0 -translate-y-1/2"></div>
                    <div class="orbital-dot absolute top-0 left-1/2 -translate-x-1/2 opacity-50" style="width: 4px; height: 4px;"></div>
                </div>

                {{-- Tick ring behind orbital --}}
                <div class="tick-ring absolute -right-32 top-1/2 -translate-y-1/2 w-[400px] h-[400px] animate-pulse-opacity"></div>

                {{-- Small floating orbital --}}
                <div class="orbital absolute left-8 bottom-8 hidden lg:block animate-float" style="--orbital-size: 80px; opacity: 0.4;">
                    <div class="orbital-dot absolute top-1/2 left-0 -translate-y-1/2" style="width: 3px; height: 3px;"></div>
                </div>

                {{-- Frequency bars decoration --}}
                <div class="absolute left-1/2 top-8 -translate-x-1/2 hidden md:flex">
                    <div class="frequency-bars">
                        @foreach([12, 20, 8, 28, 16, 32, 24, 14, 36, 18, 10, 26, 22, 30, 12, 20, 8, 28, 16] as $i => $height)
                            <span style="height: {{ $height }}px; animation-delay: {{ $i * 0.1 }}s;"></span>
                        @endforeach
                    </div>
                </div>

                {{-- Arc group top-left --}}
                <div class="arc-group absolute -left-20 -top-20 opacity-20">
                    <div class="arc w-40 h-40"></div>
                    <div class="arc w-56 h-56" style="top: -32px; left: -32px;"></div>
                    <div class="arc w-72 h-72 border-dashed" style="top: -64px; left: -64px;"></div>
                </div>

                {{-- Wave SVG --}}
                <svg class="wave-line absolute right-1/4 bottom-16 hidden lg:block" viewBox="0 0 200 60">
                    <path d="M0,30 Q25,10 50,30 T100,30 T150,30 T200,30" fill="none" stroke="currentColor" stroke-width="1" class="text-[var(--color-border)]"/>
                </svg>

                <div class="max-w-5xl mx-auto px-6 lg:px-8 relative z-10">
                    <div class="max-w-2xl">
                        {{-- Date decoration --}}
                        <div class="flex items-center gap-4 mb-8">
                            <span class="font-mono text-xs text-[var(--color-text-muted)] tracking-widest">{{ now()->format('Y') }}</span>
                            <div class="flex gap-1">
                                @for($i = 0; $i < 5; $i++)
                                    <span class="w-1.5 h-1.5 {{ $i === 0 ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-border)]' }}"></span>
                                @endfor
                            </div>
                        </div>

                        <pre class="font-mono text-xs text-[var(--color-secondary-accent)] leading-tight mb-6 whitespace-pre">
                            <code> 
      ____       __ 
  ___/ / /__ _  / / 
 (_-<_  _/  ' \/ _ \
/___//_//_/_/_/_.__/
                    
                            </code></pre>
                        <p class="text-lg md:text-xl text-[var(--color-text)] leading-relaxed mb-8 max-w-lg">
                            Developer and writer. Sharing thoughts on 
                            <em class="text-[var(--color-darkest)]">code</em>, 
                            <em class="text-[var(--color-darkest)]">creativity</em>, and building things that matter.
                        </p>

                        {{-- Decorative line with dot --}}
                        <div class="flex items-center gap-4">
                            <div class="line-dot">
                                <span class="dot"></span>
                            </div>
                            <div class="dashed-circle w-8 h-8"></div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Posts Section --}}
            <main id="posts" class="py-16 relative min-h-[400px]">
                {{-- Ruled lines decoration (left side) --}}
                <div class="ruled-lines absolute left-0 top-0 bottom-0 w-16 hidden lg:block"></div>

                {{-- Small orbital accent --}}
                <div class="absolute right-8 top-1/3 hidden xl:block">
                    <div class="orbital animate-slow-spin" style="--orbital-size: 60px; opacity: 0.3; animation-duration: 60s;">
                        <div class="orbital-dot absolute top-0 left-1/2 -translate-x-1/2" style="width: 3px; height: 3px;"></div>
                    </div>
                </div>

                <div class="max-w-5xl mx-auto px-6 lg:px-8">
                    {{-- Section header --}}
                    <div class="flex items-center justify-between mb-12">
                        <div class="section-line flex-1">
                            <span class="font-mono text-xs text-[var(--color-text-muted)] tracking-widest uppercase">posts</span>
                        </div>
                        <div class="flex items-center gap-3">
                            {{-- Small frequency bars --}}
                            <div class="frequency-bars hidden sm:flex" style="height: 16px;">
                                @foreach([6, 10, 4, 12, 8, 14, 10, 6] as $height)
                                    <span style="height: {{ $height }}px;"></span>
                                @endforeach
                            </div>
                            <div class="section-line-end">
                                <span class="dot"></span>
                            </div>
                        </div>
                    </div>

                    @if($posts->isEmpty())
                        <div class="py-16 text-center relative">
                            {{-- Empty state decoration --}}
                            <div class="dashed-circle w-32 h-32 mx-auto mb-8 flex items-center justify-center">
                                <div class="w-2 h-2 bg-[var(--color-border)]"></div>
                            </div>
                            <p class="text-[var(--color-text-muted)] font-mono text-sm">No posts yet. Check back soon.</p>
                        </div>
                    @else
                        <div class="space-y-0">
                            @foreach($posts as $index => $post)
                                <article class="group border-b border-[var(--color-border)] last:border-b-0 relative">
                                    {{-- Index number --}}
                                    <span class="absolute -left-8 top-1/2 -translate-y-1/2 font-mono text-xs text-[var(--color-border)] hidden lg:block" style="width: 2ch; text-align: right;">
                                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                                    </span>

                                    <a href="{{ route('posts.show', $post) }}" class="block py-6 transition-colors hover:bg-[var(--color-surface)]/30 -mx-4 px-4">
                                        <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
                                            {{-- Post title and excerpt --}}
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <h2 class="text-xl md:text-2xl font-light text-[var(--color-darkest)] group-hover:text-[var(--color-secondary-accent)] transition-colors">
                                                        {{ $post->title }}
                                                    </h2>
                                                    <svg class="w-4 h-4 text-[var(--color-text-muted)] group-hover:text-[var(--color-accent)] transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width: 16px; height: 16px;">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 17L17 7M17 7H7M17 7V17"/>
                                                    </svg>
                                                </div>
                                                <p class="text-sm text-[var(--color-text-muted)] font-mono line-clamp-2">
                                                    {{ Str::limit($post->display_excerpt, 200) }}
                                                </p>
                                            </div>

                                            {{-- Post date --}}
                                            <div class="flex items-center gap-2 flex-shrink-0">
                                                <span class="w-1 h-1 bg-[var(--color-accent)]" style="width: 4px; height: 4px;"></span>
                                                <time class="text-xs text-[var(--color-text-muted)] font-mono whitespace-nowrap">
                                                    {{ $post->created_at->format('M Y') }}
                                                </time>
                                            </div>
                                        </div>
                                    </a>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </main>

            {{-- Footer --}}
            <footer class="border-t border-[var(--color-border)] py-12 mt-auto relative">
                {{-- Arc decoration --}}
                <div class="arc-group absolute -right-16 -bottom-16 opacity-15">
                    <div class="arc w-32 h-32" style="transform: rotate(180deg);"></div>
                    <div class="arc w-48 h-48" style="transform: rotate(180deg); top: -32px; right: -32px;"></div>
                </div>

                <div class="max-w-5xl mx-auto px-6 lg:px-8 relative z-10">
                    <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                        <div class="flex items-center gap-6">
                            <a href="#" class="font-mono text-xs text-[var(--color-text-muted)] hover:text-[var(--color-secondary-accent)] transition-colors">GitHub</a>
                            <span class="w-1 h-1 bg-[var(--color-border)]"></span>
                            <a href="#" class="font-mono text-xs text-[var(--color-text-muted)] hover:text-[var(--color-secondary-accent)] transition-colors">Twitter</a>
                            <span class="w-1 h-1 bg-[var(--color-border)]"></span>
                            <a href="#" class="font-mono text-xs text-[var(--color-text-muted)] hover:text-[var(--color-secondary-accent)] transition-colors">LinkedIn</a>
                        </div>
                        
                        <div class="flex items-center gap-4">
                            {{-- Mini dots --}}
                            <div class="flex gap-1.5">
                                @for($i = 0; $i < 3; $i++)
                                    <span class="w-1 h-1 bg-[var(--color-accent)]"></span>
                                @endfor
                            </div>
                            <p class="font-mono text-xs text-[var(--color-text-muted)]">
                                © {{ date('Y') }} {{ config('app.name', 'Sam') }}
                            </p>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </body>
</html>
