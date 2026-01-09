@extends('errors.layout', ['dots' => 3])

@section('title', 'Forbidden')
@section('description', "You don't have access to this page.")
@section('label', 'FORBIDDEN')
@section('code', '403')
@section('message', "You don't have access to this page")

@section('decorations')
    {{-- Arc group --}}
    <div class="arc-group absolute -left-20 -top-20 opacity-20">
        <div class="arc w-40 h-40"></div>
        <div class="arc w-56 h-56" style="top: -32px; left: -32px;"></div>
        <div class="arc w-72 h-72 border-dashed" style="top: -64px; left: -64px;"></div>
    </div>

    {{-- Tick ring --}}
    <div class="tick-ring absolute right-8 bottom-8 w-[300px] h-[300px] animate-pulse-opacity hidden lg:block"></div>
@endsection

@section('action')
    <a href="{{ url('/') }}" class="inline-flex items-center gap-2 font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors hover-line">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to Home
    </a>
@endsection
