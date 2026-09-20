@include('filament.app.partials.floor-layout-switch', [
    'mode' => $mode ?? 'desktop',
    'desktopUrl' => $desktopUrl,
    'floorUrl' => $floorUrl,
])