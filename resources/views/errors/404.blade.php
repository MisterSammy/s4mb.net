@extends('errors.layout', ['dots' => 4])

@section('title', 'Page Not Found')
@section('description', 'The page you are looking for could not be found.')
@section('label', 'ERROR')
@section('code', '404')
@section('message', 'This page has wandered off somewhere')

@section('decorations')
    {{-- Large orbital decoration --}}
    <div class="orbital absolute -left-32 top-1/2 -translate-y-1/2 animate-slow-spin" style="--orbital-size: 400px;" aria-hidden="true">
        <div class="orbital-ring absolute inset-[30%]"></div>
        <div class="orbital-ring absolute inset-[45%] border-dashed"></div>
        <div class="orbital-dot absolute top-1/2 left-0 -translate-y-1/2"></div>
    </div>

    {{-- Arc group bottom-right --}}
    <div class="arc-group absolute -right-20 -bottom-20 opacity-20" aria-hidden="true">
        <div class="arc w-40 h-40" style="transform: rotate(180deg);"></div>
        <div class="arc w-56 h-56" style="transform: rotate(180deg); top: -32px; right: -32px;"></div>
        <div class="arc w-72 h-72 border-dashed" style="transform: rotate(180deg); top: -64px; right: -64px;"></div>
    </div>

    {{-- Tick ring --}}
    <div class="tick-ring absolute right-1/4 top-8 w-[200px] h-[200px] animate-pulse-opacity hidden lg:block" aria-hidden="true"></div>
@endsection

@section('action')
    <a href="{{ url('/') }}" class="inline-flex items-center gap-2 font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors hover-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)] focus-visible:outline-offset-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to Home
    </a>
@endsection
