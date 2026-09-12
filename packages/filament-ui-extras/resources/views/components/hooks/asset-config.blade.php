@props([
    'faviconSpinner' => false,
    'stickyTableActions' => false,
    'loadingBar' => true,
])

<script>
    window.FilamentUiExtras = Object.assign({}, window.FilamentUiExtras || {}, {
        faviconSpinner: @js((bool) $faviconSpinner),
        stickyTableActions: @js((bool) $stickyTableActions),
        loadingBar: @js((bool) $loadingBar),
    });
</script>

@if ($stickyTableActions)
    @php
        $stickyCssPath = \Tracepharma\FilamentUiExtras\FilamentUiExtrasServiceProvider::packagePath('resources/css/filament-ui-extras-sticky-table.css');
    @endphp
    @if (is_file($stickyCssPath))
        <style>{!! file_get_contents($stickyCssPath) !!}</style>
    @endif
@endif
