@props([
    'livewire' => null,
])

@php
    use Filament\Livewire\Notifications;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="fi" data-theme="tracepharma">
    <head>
        <meta charset="utf-8" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        @if ($favicon = filament()->getFavicon())
            <link rel="icon" href="{{ $favicon }}" />
        @endif

        <title>Floor — {{ filament()->getBrandName() }}</title>

        <style>
            [x-cloak=''],
            [x-cloak='x-cloak'],
            [x-cloak='1'] {
                display: none !important;
            }
        </style>

        @filamentStyles
        {{ filament()->getTheme()->getHtml() }}
        {{ filament()->getFontHtml() }}

        @php($viewportJs = public_path('js/tp-floor-viewport.js'))
        <script src="{{ asset('js/tp-floor-viewport.js') }}?v={{ is_file($viewportJs) ? filemtime($viewportJs) : time() }}"></script>
    </head>
    <body
        {{
            ($attributes ?? new \Illuminate\View\ComponentAttributeBag())
                ->merge($livewire?->getExtraBodyAttributes() ?? [], escape: false)
                ->class([
                    'floor-shell',
                    'tp-floor-shell-page',
                    'tp-floor-launcher-page',
                    'fi-body',
                    'fi-panel-' . filament()->getId(),
                ])
        }}
    >
        <main class="floor-shell__main min-h-dvh bg-base-200 pb-20">
            {{ $slot }}
        </main>

        @isset($footerNav)
            {!! $footerNav !!}
        @endisset

        @livewire(Notifications::class)

        @filamentScripts(withCore: true)
    </body>
</html>
