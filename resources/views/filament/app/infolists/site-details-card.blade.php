@php
    /** @var \App\Models\Site|null $record */
    $record = $record ?? null;
    $compact = $compact ?? false;
    $linkableCounts = $linkableCounts ?? true;
@endphp

@if ($record)
    <div class="card w-full bg-base-100 shadow-xl">
        <div @class(['card-body', 'gap-4' => ! $compact, 'gap-3' => $compact])>
            @include('filament.admin.infolists.catalog-site-profile', [
                'record' => $record,
                'compact' => $compact,
            ])
            @include('filament.app.infolists.site-atp-readiness', [
                'record' => $record,
                'compact' => $compact,
                'linkableCounts' => $linkableCounts,
            ])
        </div>
    </div>
@endif
