@extends('errors.layout', ['dots' => 5])

@section('title', 'Server Error')
@section('description', 'A server error has occurred. Please try again later.')
@section('label', 'ERROR')
@section('code', '500')
@section('message', 'Something went wrong on our end')

@section('decorations')
    {{-- Large orbital decoration --}}
    <div class="orbital absolute -right-32 top-1/2 -translate-y-1/2 animate-slow-spin" style="--orbital-size: 400px;">
        <div class="orbital-ring absolute inset-[30%]"></div>
        <div class="orbital-ring absolute inset-[45%] border-dashed"></div>
        <div class="orbital-dot absolute top-1/2 right-0 -translate-y-1/2"></div>
    </div>

    {{-- Arc group top-left --}}
    <div class="arc-group absolute -left-20 -top-20 opacity-20">
        <div class="arc w-40 h-40"></div>
        <div class="arc w-56 h-56" style="top: -32px; left: -32px;"></div>
    </div>
@endsection

@section('action')
    <a href="{{ url('/') }}" class="inline-flex items-center gap-2 font-mono text-sm text-[var(--color-text-muted)] hover:text-[var(--color-darkest)] transition-colors hover-line">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to Home
    </a>
@endsection
