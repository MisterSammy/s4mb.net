<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ Str::limit(strip_tags(str($post->content)->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false])), 160) }}">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="dns-prefetch" href="https://fonts.gstatic.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
        <title>{{ $post->title }} — {{ config('app.name', 'Sam') }}</title>
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
                            <a href="{{ url('/') }}" class="corner-brackets">
                                <div class="w-8 h-8 bg-[var(--color-darkest)] flex items-center justify-center pixel-shadow">
                                    <span class="text-[var(--color-background)] text-sm font-medium">S</span>
                                </div>
                            </a>
                        </div>

                        {{-- Navigation --}}
                        <div class="flex items-center gap-8">
                            <a href="{{ url('/') }}#posts" class="font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors hover-line">
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

            {{-- Post Content Section --}}
            <main class="py-16 relative min-h-[400px]">
                {{-- Ruled lines decoration (left side) --}}
                <div class="ruled-lines absolute left-0 top-0 bottom-0 w-16 hidden lg:block"></div>

                {{-- Small orbital accent --}}
                <div class="absolute right-8 top-1/3 hidden xl:block">
                    <div class="orbital animate-slow-spin" style="--orbital-size: 60px; opacity: 0.3; animation-duration: 60s;">
                        <div class="orbital-dot absolute top-0 left-1/2 -translate-x-1/2" style="width: 3px; height: 3px;"></div>
                    </div>
                </div>

                <div class="max-w-5xl mx-auto px-6 lg:px-8">
                    <article class="max-w-3xl mx-auto">
                        {{-- Post Meta --}}
                        <div class="flex items-center gap-4 mb-8">
                            <time class="font-mono text-xs text-[var(--color-text-muted)] tracking-widest">
                                {{ $post->created_at->format('M Y') }}
                            </time>
                            <div class="flex gap-1">
                                @for($i = 0; $i < 5; $i++)
                                    <span class="w-1.5 h-1.5 {{ $i === 0 ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-border)]' }}"></span>
                                @endfor
                            </div>
                        </div>

                        {{-- Post Title --}}
                        <h1 class="text-4xl md:text-5xl lg:text-6xl font-light text-[var(--color-darkest)] tracking-tight mb-8">
                            {{ $post->title }}
                        </h1>

                        {{-- Decorative line with dot --}}
                        <div class="flex items-center gap-4 mb-12">
                            <div class="line-dot">
                                <span class="dot"></span>
                            </div>
                            <div class="dashed-circle w-8 h-8"></div>
                        </div>

                        {{-- Post Content --}}
                        <div class="prose max-w-none text-lg leading-relaxed">
                            {!! str($post->content)->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>

                        {{-- Post Images with Captions --}}
                        @if($post->images->isNotEmpty())
                            <div class="mt-12 space-y-8">
                                @foreach($post->images as $image)
                                    <figure class="space-y-3">
                                        <img 
                                            src="{{ $image->url }}" 
                                            alt="{{ $image->alt_text ?? $image->caption ?? $post->title }}"
                                            class="w-full border border-[var(--color-border)] pixel-shadow"
                                            loading="lazy"
                                        >
                                        @if($image->caption)
                                            <figcaption class="text-sm text-[var(--color-text-muted)] font-mono text-center italic">
                                                {{ $image->caption }}
                                            </figcaption>
                                        @endif
                                    </figure>
                                @endforeach
                            </div>
                        @endif

                        {{-- Back to Posts Link --}}
                        <div class="mt-16 pt-8 border-t border-[var(--color-border)]">
                            <a href="{{ url('/') }}#posts" class="inline-flex items-center gap-2 font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-secondary-accent)] transition-colors">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                                </svg>
                                <span class="hover-line">Back to Posts</span>
                            </a>
                        </div>
                    </article>
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
