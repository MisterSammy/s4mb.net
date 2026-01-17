@extends('layouts.app')

@section('title', $post->title . ' — ' . config('app.name', 'Sam'))
@section('meta_description', Str::limit($post->getDisplayExcerpt(), 160))

@section('content')
    {{-- Reading progress bar --}}
    <div class="reading-progress" id="reading-progress"></div>

    {{-- Post Content Section --}}
    <main id="main-content" class="py-16 relative min-h-[400px]">
        {{-- Ruled lines decoration (left side) --}}
        <div class="ruled-lines absolute left-0 top-0 bottom-0 w-16 hidden lg:block" aria-hidden="true"></div>

        {{-- Small orbital accent --}}
        <div class="absolute right-8 top-1/3 hidden xl:block" aria-hidden="true">
            <div class="orbital animate-slow-spin" style="--orbital-size: 60px; opacity: 0.3; animation-duration: 60s;">
                <div class="orbital-dot absolute top-0 left-1/2 -translate-x-1/2" style="width: 3px; height: 3px;"></div>
            </div>
        </div>

        <div class="max-w-6xl mx-auto px-6 lg:px-8">
            <article class="max-w-4xl mx-auto">
                {{-- Post Meta --}}
                <div class="flex items-center gap-4 mb-8">
                    <time class="font-mono text-xs text-[var(--color-text-muted)] tracking-widest" datetime="{{ $post->date->toIso8601String() }}">
                        {{ $post->date->format('M Y') }}
                    </time>
                    <div class="flex gap-1" aria-hidden="true">
                        @for($i = 0; $i < 5; $i++)
                            <span class="w-1.5 h-1.5 {{ $i === 0 ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-border)]' }}"></span>
                        @endfor
                    </div>
                </div>

                {{-- Post Title --}}
                <h1 class="font-serif text-4xl md:text-5xl lg:text-6xl font-light text-[var(--color-darkest)] tracking-tight mb-8">
                    {{ $post->title }}
                </h1>

                {{-- Table of Contents --}}
                @if(isset($headings) && $headings->count() >= 2)
                <nav class="mb-8" aria-label="Table of contents">
                    <ul class="space-y-2">
                        @foreach($headings as $heading)
                            <li>
                                <a href="#{{ $heading['slug'] }}" 
                                   class="font-mono text-sm text-[var(--color-text-muted)] transition-colors hover-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2">
                                    {{ $heading['text'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
                @endif

                {{-- Decorative line with dot --}}
                <div class="flex items-center gap-4 mb-12" aria-hidden="true">
                    <div class="line-dot">
                        <span class="dot"></span>
                    </div>
                    <div class="dashed-circle w-8 h-8"></div>
                </div>

                {{-- Post Content --}}
                <div class="prose font-sans max-w-none text-lg leading-relaxed">
                    {!! $htmlContent ?? str($post->content)->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                </div>

                {{-- Back to Posts Link --}}
                <div class="mt-16 pt-8 border-t border-[var(--color-border)]">
                    <a href="{{ url('/') }}#posts" class="inline-flex items-center gap-2 font-mono text-sm text-[var(--color-text-muted)] transition-colors hover-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        <span>Back to Posts</span>
                    </a>
                </div>
            </article>
        </div>
    </main>
@endsection
