@php
    $settingsUrl = \App\Filament\App\Pages\OrganizationSettings::getUrl(panel: 'app');
@endphp

<div class="alert alert-warning mb-4" data-tp-identity-incomplete>
    <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <span>
            Identity incomplete — company GLN or GS1 Company Prefix is blank. Pack, SSCC, and ship authoring cannot derive SGLN until both are set.
        </span>
        <a href="{{ $settingsUrl }}" class="btn btn-sm btn-warning shrink-0">
            Organization settings
        </a>
    </div>
</div>
