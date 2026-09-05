@php
    $jsPath = \Tracepharma\FilamentUiExtras\FilamentUiExtrasServiceProvider::packagePath('resources/js/filament-ui-extras.js');
@endphp

@if (is_file($jsPath))
    <script data-navigate-once>
        {!! file_get_contents($jsPath) !!}
    </script>
@endif
