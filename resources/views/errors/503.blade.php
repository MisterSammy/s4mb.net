<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Service is temporarily unavailable. We'll be right back.">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="dns-prefetch" href="https://fonts.gstatic.com">
        <title>Service Unavailable — {{ config('app.name', 'Sam') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-obsidian text-fog min-h-screen font-sans antialiased overflow-x-hidden">
        {{-- Subtle dot grid background --}}
        <div class="fixed inset-0 pointer-events-none dot-matrix" style="--dot-gap: 40px; --dot-size: 1px;"></div>

        <div class="relative min-h-screen flex flex-col">
            {{-- Header --}}
            <header class="border-b border-charcoal relative z-10">
                <div class="max-w-5xl mx-auto px-6 lg:px-8">
                    <nav class="flex items-center justify-between h-16">
                        <div class="flex items-center gap-3">
                            <div class="corner-brackets">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-steel to-charcoal flex items-center justify-center">
                                    <span class="text-snow text-sm font-medium">S</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            {{-- Frequency bars --}}
                            <div class="frequency-bars" style="height: 16px;">
                                @foreach([6, 10, 4, 12, 8, 14, 10, 6] as $height)
                                    <span style="height: {{ $height }}px;"></span>
                                @endforeach
                            </div>
                        </div>
                    </nav>
                </div>
            </header>

            {{-- Error Content --}}
            <main class="flex-1 flex items-center justify-center py-16 relative overflow-hidden">
                {{-- Large orbital decoration --}}
                <div class="orbital absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 animate-slow-spin" style="--orbital-size: 600px; opacity: 0.15;">
                    <div class="orbital-ring absolute inset-[20%]"></div>
                    <div class="orbital-ring absolute inset-[35%] border-dashed"></div>
                    <div class="orbital-ring absolute inset-[50%]"></div>
                    <div class="orbital-dot absolute top-1/2 right-0 -translate-y-1/2"></div>
                </div>

                <div class="max-w-5xl mx-auto px-6 lg:px-8 relative z-10">
                    <div class="text-center">
                        {{-- Error Code --}}
                        <div class="flex items-center justify-center gap-4 mb-8">
                            <span class="font-mono text-xs text-mist tracking-widest">MAINTENANCE</span>
                            <div class="flex gap-1">
                                @for($i = 0; $i < 3; $i++)
                                    <span class="w-1.5 h-1.5 rounded-full {{ $i === 0 ? 'bg-silver' : 'bg-steel' }}"></span>
                                @endfor
                            </div>
                        </div>

                        <h1 class="text-8xl md:text-9xl font-light text-snow tracking-tight mb-4">
                            503
                        </h1>

                        <p class="text-xl md:text-2xl text-silver leading-relaxed mb-8 max-w-lg mx-auto">
                            We'll be right back
                        </p>

                        {{-- Dog SVG --}}
                        <div class="flex justify-center mb-12">
                            <div class="dashed-circle w-48 h-48 md:w-64 md:h-64 flex items-center justify-center p-8 animate-float">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="w-full h-full text-silver">
                                    <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1">
                                        <line x1="25.129" y1="17" x2="22.181" y2="19.948"/>
                                        <line x1="22.181" y1="17" x2="25.129" y2="19.948"/>
                                        <circle cx="34.5" cy="10.5" r="2.5"/>
                                        <circle cx="14" cy="10" r="1" fill="currentColor" stroke="none"/>
                                        <line x1="14.861" y1="48.722" x2="12.75" y2="44.5"/>
                                        <line x1="10.288" y1="46.146" x2="11.875" y2="48.792"/>
                                        <path d="M13.437,30.379l-5.988.367A3.722,3.722,0,0,0,4,34.458H4A3.722,3.722,0,0,1,2.91,37.09L1,39"/>
                                        <path d="M44,49h8.469A7.531,7.531,0,0,1,60,56.531V61"/>
                                        <line x1="11.53" y1="49" x2="40.23" y2="49"/>
                                        <path d="M1,61V56.531a7.532,7.532,0,0,1,7-7.513"/>
                                        <polyline points="51.861 48.722 51 47 49 46 49.583 42.5"/>
                                        <polyline points="46.4 44 46 46 46.907 48.722"/>
                                        <path d="M44.412,23.774,42,29s-20-2-28,1c-6.621,2.483-4,8-5,10a47.085,47.085,0,0,0-2,5l2,6h3l-2-5a32.25,32.25,0,0,0,7.288-5.672C23.3,37.51,25.769,41.192,37,44a19.757,19.757,0,0,0,3.855.581C40.642,45.43,40,48,40,48l2,4h4l-2-2-1-3,1.819-2.729a12.24,12.24,0,0,0,6.9-4.292,6.4,6.4,0,0,0,1.4-3.532L54,25h5.149a3,3,0,0,0,2.679-1.656L63,21l-6-3-1-2H51"/>
                                        <path d="M44,16h0a7,7,0,0,1,7,7v2.387A2.613,2.613,0,0,1,48.387,28H44.8a.8.8,0,0,1-.8-.8V16A0,0,0,0,1,44,16Z" transform="translate(95 44) rotate(180)"/>
                                        <circle cx="55" cy="19" r="1" fill="currentColor" stroke="none"/>
                                        <line x1="63" y1="21" x2="61" y2="21"/>
                                        <circle cx="54" cy="56" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="51" cy="53" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="48" cy="58" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="31" cy="58" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="25" cy="59" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="41" cy="57" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="36" cy="59" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="19" cy="53" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="35" cy="54" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="10" cy="59" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="24" cy="55" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="15" cy="56" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="19" cy="58" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="30" cy="53" r="1" fill="currentColor" stroke="none"/>
                                        <circle cx="8" cy="55" r="1" fill="currentColor" stroke="none"/>
                                        <path d="M19,35s1.454,1.777-1.71,5.33"/>
                                    </g>
                                </svg>
                            </div>
                        </div>

                        {{-- Decorative line with dot --}}
                        <div class="flex items-center justify-center gap-4">
                            <div class="line-dot">
                                <span class="dot"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            {{-- Footer --}}
            <footer class="border-t border-charcoal py-12 relative">
                <div class="max-w-5xl mx-auto px-6 lg:px-8 relative z-10">
                    <div class="flex items-center justify-center gap-4">
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
            </footer>
        </div>
    </body>
</html>

