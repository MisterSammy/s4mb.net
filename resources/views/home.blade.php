<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Developer and writer. Sharing thoughts on code, creativity, and building things that matter.">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="dns-prefetch" href="https://fonts.gstatic.com">
        <title>{{ config('app.name', 'Sam') }} — Blog</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-obsidian text-fog min-h-screen font-sans antialiased overflow-x-hidden">
        {{-- Subtle dot grid background --}}
        <div class="fixed inset-0 pointer-events-none"></div>

        <div class="relative min-h-screen">
            {{-- Header --}}
            <header class="border-b border-charcoal relative z-10">
                <div class="max-w-5xl mx-auto px-6 lg:px-8">
                    <nav class="flex items-center justify-between h-16">
                        {{-- Logo/Avatar --}}
                        <div class="flex items-center gap-3">
                            <div class="corner-brackets">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-steel to-charcoal flex items-center justify-center">
                                    <span class="text-snow text-sm font-medium">S</span>
                                </div>
                            </div>
                        </div>

                        {{-- Navigation --}}
                        <div class="flex items-center gap-8">
                            <a href="#posts" class="font-mono text-sm text-silver hover:text-snow transition-colors hover-line">
                                Posts
                            </a>
                            <span class="text-steel">—</span>
                            <a href="#" class="font-mono text-sm text-silver hover:text-snow transition-colors hover-line">
                                Elsewhere
                            </a>
                            <div class="corner-brackets ml-2">
                                <span class="text-xs text-mist font-mono">↗</span>
                            </div>
                        </div>
                    </nav>
                </div>
            </header>

            {{-- Hero Section with Geometric Decorations --}}
            <section class="pt-24 pb-20 border-b border-charcoal relative overflow-hidden">
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
                    <path d="M0,30 Q25,10 50,30 T100,30 T150,30 T200,30" fill="none" stroke="currentColor" stroke-width="1" class="text-steel"/>
                </svg>

                <div class="max-w-5xl mx-auto px-6 lg:px-8 relative z-10">
                    <div class="max-w-2xl">
                        {{-- Date decoration --}}
                        <div class="flex items-center gap-4 mb-8">
                            <span class="font-mono text-xs text-mist tracking-widest">{{ now()->format('Y') }}</span>
                            <div class="flex gap-1">
                                @for($i = 0; $i < 5; $i++)
                                    <span class="w-1.5 h-1.5 rounded-full {{ $i === 0 ? 'bg-silver' : 'bg-steel' }}"></span>
                                @endfor
                            </div>
                        </div>

                        <h1 class="text-4xl md:text-5xl lg:text-6xl font-light text-snow tracking-tight mb-6">
                            {{ config('app.name', 'Sam') }}
                        </h1>
                        <p class="text-lg md:text-xl text-silver leading-relaxed mb-8 max-w-lg">
                            Developer and writer. Sharing thoughts on 
                            <em class="text-fog">code</em>, 
                            <em class="text-fog">creativity</em>, and building things that matter.
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
                            <span class="font-mono text-xs text-mist tracking-widest uppercase">posts</span>
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
                                <div class="w-2 h-2 rounded-full bg-steel"></div>
                            </div>
                            <p class="text-silver font-mono text-sm">No posts yet. Check back soon.</p>
                        </div>
                    @else
                        <div class="space-y-0">
                            @foreach($posts as $index => $post)
                                <article class="group border-b border-charcoal last:border-b-0 relative">
                                    {{-- Index number --}}
                                    <span class="absolute -left-8 top-1/2 -translate-y-1/2 font-mono text-xs text-steel hidden lg:block" style="width: 2ch; text-align: right;">
                                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                                    </span>

                                    <a href="{{ route('posts.show', $post) }}" class="flex flex-col md:flex-row md:items-center justify-between py-6 gap-4 transition-colors hover:bg-charcoal/30 -mx-4 px-4 rounded" style="min-height: 60px;">
                                        {{-- Post title with arrow --}}
                                        <div class="flex items-center gap-3 min-w-0 flex-1">
                                            <h2 class="text-xl md:text-2xl font-light text-cloud group-hover:text-snow transition-colors">
                                                {{ $post->title }}
                                            </h2>
                                            <svg class="w-4 h-4 text-mist group-hover:text-silver transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width: 16px; height: 16px;">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 17L17 7M17 7H7M17 7V17"/>
                                            </svg>
                                        </div>

                                        {{-- Post meta --}}
                                        <div class="flex items-center gap-4 flex-shrink-0">
                                            <span class="text-sm text-mist font-mono line-clamp-1 max-w-[200px]">
                                                {{ Str::limit(strip_tags(str($post->content)->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false])), 40) }}
                                            </span>
                                            <div class="flex items-center gap-2">
                                                <span class="w-1 h-1 rounded-full bg-steel" style="width: 4px; height: 4px;"></span>
                                                <time class="text-xs text-steel font-mono whitespace-nowrap">
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
            <footer class="border-t border-charcoal py-12 mt-auto relative">
                {{-- Arc decoration --}}
                <div class="arc-group absolute -right-16 -bottom-16 opacity-15">
                    <div class="arc w-32 h-32" style="transform: rotate(180deg);"></div>
                    <div class="arc w-48 h-48" style="transform: rotate(180deg); top: -32px; right: -32px;"></div>
                </div>

                <div class="max-w-5xl mx-auto px-6 lg:px-8 relative z-10">
                    <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                        <div class="flex items-center gap-6">
                            <a href="#" class="font-mono text-xs text-steel hover:text-silver transition-colors">GitHub</a>
                            <span class="w-1 h-1 rounded-full bg-charcoal"></span>
                            <a href="#" class="font-mono text-xs text-steel hover:text-silver transition-colors">Twitter</a>
                            <span class="w-1 h-1 rounded-full bg-charcoal"></span>
                            <a href="#" class="font-mono text-xs text-steel hover:text-silver transition-colors">LinkedIn</a>
                        </div>
                        
                        <div class="flex items-center gap-4">
                            {{-- Mini dots --}}
                            <div class="flex gap-1.5">
                                @for($i = 0; $i < 3; $i++)
                                    <span class="w-1 h-1 rounded-full bg-steel"></span>
                                @endfor
                            </div>
                            <p class="font-mono text-xs text-steel">
                                © {{ date('Y') }} {{ config('app.name', 'Sam') }}
                            </p>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </body>
</html>
