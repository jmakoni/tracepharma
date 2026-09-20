@props([
    'progress',
    'class' => 'stats stats-vertical sm:stats-horizontal bg-base-200 shadow',
])

@php
    /** @var \App\Support\Receiving\ReceivingSessionProgress $progress */

    $stats = [
        [
            'title' => $progress->parentTypeLabel(),
            'value' => $progress->parentProgressQuantity(),
        ],
    ];

    if ($progress->showUnitsProgress()) {
        $stats[] = [
            'title' => $progress->childTypeLabel(),
            'value' => $progress->childProgressQuantity(),
        ];
    }
@endphp

@include('filament.app.partials.scanner-progress-stats', [
    'stats' => $stats,
    'ariaLabel' => $progress->ariaLabel(),
    'class' => $class,
])
