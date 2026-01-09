@extends('errors.layout', ['dots' => 3])

@section('title', 'Service Unavailable')
@section('description', "Service is temporarily unavailable. We'll be right back.")
@section('label', 'MAINTENANCE')
@section('code', '503')
@section('message', "We'll be right back")
@section('show_home_link', false)

@section('header_nav')
    <div class="flex items-center gap-4">
        {{-- Frequency bars --}}
        <div class="frequency-bars" style="height: 16px;">
            @foreach([6, 10, 4, 12, 8, 14, 10, 6] as $height)
                <span style="height: {{ $height }}px;"></span>
            @endforeach
        </div>
    </div>
@endsection

@section('decorations')
    {{-- Large orbital decoration --}}
    <div class="orbital absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 animate-slow-spin" style="--orbital-size: 600px; opacity: 0.15;">
        <div class="orbital-ring absolute inset-[20%]"></div>
        <div class="orbital-ring absolute inset-[35%] border-dashed"></div>
        <div class="orbital-ring absolute inset-[50%]"></div>
        <div class="orbital-dot absolute top-1/2 right-0 -translate-y-1/2"></div>
    </div>
@endsection

@section('action')
    {{-- No action for maintenance page --}}
@endsection

@section('footer')
    <div class="flex items-center justify-center gap-4">
        <div class="flex gap-1.5">
            @for($i = 0; $i < 3; $i++)
                <span class="w-1 h-1 rounded-full bg-[var(--color-border)]"></span>
            @endfor
        </div>
        <p class="font-mono text-xs text-[var(--color-text-muted)]">
            © {{ date('Y') }} {{ config('app.name', 'Sam') }}
        </p>
    </div>
@endsection
