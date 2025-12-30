<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ Str::limit(strip_tags(str($post->content)->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false])), 160) }}">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="dns-prefetch" href="https://fonts.gstatic.com">
        <title>{{ $post->title }} — {{ config('app.name', 'Sam') }}</title>
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
                            <a href="{{ url('/') }}" class="corner-brackets">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-steel to-charcoal flex items-center justify-center">
                                    <span class="text-snow text-sm font-medium">S</span>
                                </div>
                            </a>
                        </div>

                        {{-- Navigation --}}
                        <div class="flex items-center gap-8">
                            <a href="{{ url('/') }}#posts" class="font-mono text-sm text-silver hover:text-snow transition-colors hover-line">
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
                            <time class="font-mono text-xs text-mist tracking-widest">
                                {{ $post->created_at->format('M Y') }}
                            </time>
                            <div class="flex gap-1">
                                @for($i = 0; $i < 5; $i++)
                                    <span class="w-1.5 h-1.5 rounded-full {{ $i === 0 ? 'bg-silver' : 'bg-steel' }}"></span>
                                @endfor
                            </div>
                        </div>

                        {{-- Post Title --}}
                        <h1 class="text-4xl md:text-5xl lg:text-6xl font-light text-snow tracking-tight mb-8">
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
                        <div class="prose prose-invert max-w-none text-lg text-silver leading-relaxed">
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
                                            class="w-full rounded-lg border border-charcoal"
                                            loading="lazy"
                                        >
                                        @if($image->caption)
                                            <figcaption class="text-sm text-mist font-mono text-center italic">
                                                {{ $image->caption }}
                                            </figcaption>
                                        @endif
                                    </figure>
                                @endforeach
                            </div>
                        @endif

                        {{-- Back to Posts Link --}}
                        <div class="mt-16 pt-8 border-t border-charcoal">
                            <a href="{{ url('/') }}#posts" class="inline-flex items-center gap-2 font-mono text-sm text-silver hover:text-snow transition-colors">
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

