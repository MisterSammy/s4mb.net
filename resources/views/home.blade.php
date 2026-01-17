@extends('layouts.app')

@section('title', config('app.name', 'Sam') . ' — Blog')

@section('content')
    {{-- Hero Section with Geometric Decorations --}}
    <section class="pt-24 pb-20 border-b border-[var(--color-border)] relative overflow-hidden">
        <div class="dot-matrix absolute inset-0" style="--dot-gap: 40px; --dot-size: 1px;" aria-hidden="true"></div>
        {{-- Large orbital decoration --}}
        <div class="orbital absolute -right-48 top-1/2 -translate-y-1/2 animate-slow-spin" style="--orbital-size: 500px;" aria-hidden="true">
            <div class="orbital-ring absolute inset-[30%]"></div>
            <div class="orbital-ring absolute inset-[45%] border-dashed"></div>
            <div class="orbital-dot absolute top-1/2 right-0 -translate-y-1/2"></div>
            <div class="orbital-dot absolute top-0 left-1/2 -translate-x-1/2 opacity-50" style="width: 4px; height: 4px;"></div>
        </div>

        {{-- Tick ring behind orbital --}}
        <div class="tick-ring absolute -right-32 top-1/2 -translate-y-1/2 w-[400px] h-[400px] animate-pulse-opacity" aria-hidden="true"></div>

        {{-- Small floating orbital --}}
        <div class="orbital absolute left-8 bottom-8 hidden lg:block animate-float" style="--orbital-size: 80px; opacity: 0.4;" aria-hidden="true">
            <div class="orbital-dot absolute top-1/2 left-0 -translate-y-1/2" style="width: 3px; height: 3px;"></div>
        </div>

        {{-- Frequency bars decoration --}}
        <div class="absolute left-1/2 top-8 -translate-x-1/2 hidden md:flex" aria-hidden="true">
            <div class="frequency-bars">
                @foreach([12, 20, 8, 28, 16, 32, 24, 14, 36, 18, 10, 26, 22, 30, 12, 20, 8, 28, 16] as $i => $height)
                    <span style="height: {{ $height }}px; animation-delay: {{ $i * 0.1 }}s;"></span>
                @endforeach
            </div>
        </div>

        {{-- Arc group top-left --}}
        <div class="arc-group absolute -left-20 -top-20 opacity-20" aria-hidden="true">
            <div class="arc w-40 h-40"></div>
            <div class="arc w-56 h-56" style="top: -32px; left: -32px;"></div>
            <div class="arc w-72 h-72 border-dashed" style="top: -64px; left: -64px;"></div>
        </div>

        {{-- Wave SVG --}}
        <svg class="wave-line absolute right-1/4 bottom-16 hidden lg:block" viewBox="0 0 200 60" aria-hidden="true">
            <path d="M0,30 Q25,10 50,30 T100,30 T150,30 T200,30" fill="none" stroke="currentColor" stroke-width="1" class="text-[var(--color-border)]"/>
        </svg>

        <div class="max-w-6xl mx-auto px-6 lg:px-8 relative z-10">
            <div class="max-w-2xl">
                {{-- Date decoration --}}
                <div class="flex items-center gap-4 mb-8">
                    <span class="font-mono text-xs text-[var(--color-text-muted)] tracking-widest">{{ now()->format('Y') }}</span>
                    <div class="flex gap-1" aria-hidden="true">
                        @for($i = 0; $i < 5; $i++)
                            <span class="w-1.5 h-1.5 {{ $i === 0 ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-border)]' }}"></span>
                        @endfor
                    </div>
                </div>

                <pre class="font-mono text-xs text-[var(--color-secondary-accent)] leading-tight mb-6 whitespace-pre" aria-hidden="true">
                    <code> 
      ____       __ 
  ___/ / /__ _  / / 
 (_-<_  _/  ' \/ _ \
/___//_//_/_/_/_.__/
                    
                    </code></pre>
                <p class="text-lg md:text-xl text-[var(--color-text)] leading-relaxed mb-8 max-w-lg">
                    Developer and writer. Sharing thoughts on 
                    <em class="text-[var(--color-darkest)]">code</em>, 
                    <em class="text-[var(--color-darkest)]">creativity</em>, and building things on the internet.
                </p>

                {{-- Decorative line with dot --}}
                <div class="flex items-center gap-4" aria-hidden="true">
                    <div class="line-dot">
                        <span class="dot"></span>
                    </div>
                    <div class="dashed-circle w-8 h-8"></div>
                </div>

                {{-- Professional Experience --}}
                <div class="mt-8 space-y-4">
                    <div class="font-mono text-sm text-[var(--color-text-muted)]">
                        <span class="font-bold">1996</span> <span class="mx-2">·</span> <span>💾</span> built an RPG in BASIC on the Amstrad CPC 464
                    </div>
                    <!-- <div class="font-mono text-sm text-[var(--color-text-muted)]">
                        <span class="font-bold">1999</span> <span class="mx-2">·</span> <span>🎮</span> Played a lot of AoE on Win98 and N64 and got internet. Then life happened
                    </div> -->

                    <div class="font-mono text-sm text-[var(--color-text-muted)]">
                        <span class="font-bold">2017</span> <span class="mx-2">·</span> <span>📦</span> <a href="https://www.huboo.com" target="_blank" rel="noopener" class="text-[var(--color-text)] hover:text-[var(--color-accent)] transition-colors">New Vision</a> — modernized legacy PHP systems and architected real-time API middleware for e-commerce platforms
                    </div>
                    <div class="font-mono text-sm text-[var(--color-text-muted)]">
                        <span class="font-bold">2020</span> <span class="mx-2">·</span> <span>🎓</span> <a href="https://www.worktribe.com" target="_blank" rel="noopener" class="text-[var(--color-text)] hover:text-[var(--color-accent)] transition-colors">WorkTribe</a> — built cross-platform React Native apps with Laravel backends for university research management
                    </div>
                    <div class="font-mono text-sm text-[var(--color-text-muted)]">
                        <span class="font-bold">2021</span> <span class="mx-2">·</span> <span>⚖️</span> Stephenson Law — built and led dev team from scratch, architected mission-critical legal compliance platforms and customer portals
                    </div>
                    <div class="font-mono text-sm text-[var(--color-text-muted)]">
                        <span class="font-bold">2024</span> <span class="mx-2">·</span> <span>📺</span> <a href="https://www.simplestream.com" target="_blank" rel="noopener" class="text-[var(--color-text)] hover:text-[var(--color-accent)] transition-colors">SimpleStream</a> — scalable video streaming APIs powering platforms for Sony, News UK, and PBS America
                    </div>
                    <div class="font-mono text-sm text-[var(--color-text-muted)]">
                        <span class="font-bold">2026</span> <span class="mx-2">·</span> <span>✨</span> I'm building online tools for creatives, and the things I always wanted
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Posts Section --}}
    <main id="main-content" class="py-16 relative min-h-[400px]">
        <div id="posts" class="absolute -top-16"></div>
        {{-- Ruled lines decoration (left side) --}}
        <div class="ruled-lines absolute left-0 top-0 bottom-0 w-16 hidden lg:block" aria-hidden="true"></div>

        {{-- Small orbital accent --}}
        <div class="absolute right-8 top-1/3 hidden xl:block" aria-hidden="true">
            <div class="orbital animate-slow-spin" style="--orbital-size: 60px; opacity: 0.3; animation-duration: 60s;">
                <div class="orbital-dot absolute top-0 left-1/2 -translate-x-1/2" style="width: 3px; height: 3px;"></div>
            </div>
        </div>

        <div class="max-w-6xl mx-auto px-6 lg:px-8">
            {{-- Section header --}}
            <div class="flex items-center justify-between mb-12">
                <div class="section-line flex-1">
                    <span class="font-mono text-xs text-[var(--color-text-muted)] tracking-widest uppercase">posts</span>
                </div>
                <div class="flex items-center gap-3">
                    {{-- Small frequency bars --}}
                    <div class="frequency-bars hidden sm:flex" style="height: 16px;" aria-hidden="true">
                        @foreach([6, 10, 4, 12, 8, 14, 10, 6] as $height)
                            <span style="height: {{ $height }}px;"></span>
                        @endforeach
                    </div>
                    <div class="section-line-end" aria-hidden="true">
                        <span class="dot"></span>
                    </div>
                </div>
            </div>

            {{-- Tag Filter Bar --}}
            @if($tags->isNotEmpty())
                <div class="tag-filter-bar mb-8">
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- All button --}}
                        <button
                            type="button"
                            id="tag-filter-all"
                            class="tag-pill tag-pill--active"
                            onclick="window.TagFilter?.clear()"
                        >
                            <span class="tag-pill__label">All</span>
                        </button>

                        {{-- Tag pills --}}
                        @foreach($tags as $tag)
                            <x-tag-pill
                                :slug="$tag['slug']"
                                :label="$tag['label']"
                                :count="$tag['count']"
                                :color="$tag['color'] ?? null"
                            />
                        @endforeach
                    </div>

                    {{-- Active filters indicator --}}
                    <div id="active-filters" class="hidden items-center gap-2 mt-4 text-sm text-[var(--color-text-muted)]">
                        <span class="font-mono">Showing posts with <span class="active-filter-count font-bold text-[var(--color-accent)]">0</span> selected tag(s)</span>
                        <button
                            type="button"
                            onclick="window.TagFilter?.clear()"
                            class="text-[var(--color-accent)] hover:underline font-mono"
                        >
                            Clear filters
                        </button>
                    </div>
                </div>
            @endif

            {{-- No results message --}}
            <div id="no-results" class="hidden py-16 text-center">
                <div class="dashed-circle w-24 h-24 mx-auto mb-6 flex items-center justify-center" aria-hidden="true">
                    <svg class="w-8 h-8 text-[var(--color-border)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </div>
                <p class="text-[var(--color-text-muted)] font-mono text-sm mb-4">No posts match the selected tags.</p>
                <button
                    type="button"
                    onclick="window.TagFilter?.clear()"
                    class="text-[var(--color-accent)] hover:underline font-mono text-sm"
                >
                    Clear filters
                </button>
            </div>

            @if($posts->isEmpty())
                <div class="py-16 text-center relative">
                    {{-- Empty state decoration --}}
                    <div class="dashed-circle w-32 h-32 mx-auto mb-8 flex items-center justify-center" aria-hidden="true">
                        <div class="w-2 h-2 bg-[var(--color-border)]"></div>
                    </div>
                    <p class="text-[var(--color-text-muted)] font-mono text-sm">No posts yet. Check back soon.</p>
                </div>
            @else
                <div class="space-y-0">
                    @foreach($posts as $index => $post)
                        <article
                            class="group border-b border-[var(--color-border)] last:border-b-0 relative"
                            data-tags="{{ collect($post->tags)->map(fn($t) => \Illuminate\Support\Str::slug($t))->implode(',') }}"
                        >
                            {{-- Index number --}}
                            <span class="absolute -left-8 top-1/2 -translate-y-1/2 font-mono text-xs text-[var(--color-border)] hidden lg:block" style="width: 2ch; text-align: right;" aria-hidden="true">
                                {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                            </span>

                            <a href="{{ route('posts.show', $post->slug) }}" class="post-list-link block py-6 transition-colors hover:bg-[var(--color-surface)]/30 -mx-4 px-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2">
                                <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
                                    {{-- Post title and excerpt --}}
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-3 mb-2">
                                            <h2 class="font-serif text-xl md:text-2xl font-light text-[var(--color-darkest)] group-hover:text-[var(--color-secondary-accent)] transition-colors">
                                                {{ $post->title }}
                                            </h2>
                                            <svg class="w-4 h-4 text-[var(--color-text-muted)] group-hover:text-[var(--color-accent)] transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width: 16px; height: 16px;" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 17L17 7M17 7H7M17 7V17"/>
                                            </svg>
                                        </div>
                                        <p class="text-sm text-[var(--color-text-muted)] font-mono line-clamp-2">
                                            {{ Str::limit($post->getDisplayExcerpt(), 200) }}
                                        </p>

                                        {{-- Inline tags --}}
                                        @if(!empty($post->tags))
                                            <div class="flex flex-wrap gap-1.5 mt-3">
                                                @foreach(array_slice($post->tags, 0, 4) as $tag)
                                                    @php
                                                        $tagSlug = \Illuminate\Support\Str::slug($tag);
                                                        $tagData = app(\App\Services\TagRegistryService::class)->getTag($tagSlug);
                                                    @endphp
                                                    <span class="post-tag-inline">
                                                        {{ $tagData['label'] ?? \Illuminate\Support\Str::title(str_replace('-', ' ', $tag)) }}
                                                    </span>
                                                @endforeach
                                                @if(count($post->tags) > 4)
                                                    <span class="post-tag-inline post-tag-inline--more">+{{ count($post->tags) - 4 }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Post date --}}
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <span class="w-1 h-1 bg-[var(--color-accent)]" style="width: 4px; height: 4px;" aria-hidden="true"></span>
                                        <time class="text-xs text-[var(--color-text-muted)] font-mono whitespace-nowrap" datetime="{{ $post->date->toIso8601String() }}">
                                            {{ $post->date->format('M Y') }}
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
@endsection
