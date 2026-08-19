@php
    $wizardUrl = \App\Filament\App\Pages\OnboardingWizard::getUrl(panel: 'app');
@endphp

<div class="alert alert-warning mb-4">
    <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <span>
            Organization setup is incomplete. Finish company GLN and default sites before go-live receiving.
        </span>
        <a href="{{ $wizardUrl }}" class="btn btn-sm btn-warning shrink-0">
            Continue setup
        </a>
    </div>
</div>
