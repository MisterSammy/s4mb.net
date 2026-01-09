@extends('errors.layout', ['dots' => 4])

@section('title', 'Page Expired')
@section('description', 'Your session has expired. Please refresh and try again.')
@section('label', 'SESSION EXPIRED')
@section('code', '419')
@section('message', 'Your session has expired. Please refresh and try again.')

@section('decorations')
    {{-- Orbital decorations --}}
    <div class="orbital absolute -right-48 top-1/2 -translate-y-1/2 animate-slow-spin" style="--orbital-size: 500px;">
        <div class="orbital-ring absolute inset-[30%]"></div>
        <div class="orbital-ring absolute inset-[45%] border-dashed"></div>
        <div class="orbital-dot absolute top-1/2 right-0 -translate-y-1/2"></div>
    </div>
@endsection

@section('action')
    <button onclick="window.location.reload()" class="inline-flex items-center gap-2 font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors hover-line cursor-pointer">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Refresh Page
    </button>
@endsection
