@php
    use App\Enums\SiteAtpReadinessStatus;
    use App\Filament\Admin\Resources\Tenants\TenantResource;
    use App\Filament\App\Resources\Sites\SiteResource;
    use App\Models\Site;
    use App\Support\MasterData\AtpDisclosure;
    use App\Support\MasterData\AtpLicenseRelevance;
    use App\Support\MasterData\SiteAtpReadiness;
    use Filament\Facades\Filament;
    use Filament\Models\Contracts\FilamentUser;

    /** @var \App\Models\Site|null $record */
    $record = $record ?? null;
    $compact = $compact ?? false;
    $linkableCounts = $linkableCounts ?? false;
    $wrapInCard = $wrapInCard ?? false;

    if (! $record instanceof Site) {
        return;
    }

    $stats = SiteAtpReadiness::summarize($record);
    /** @var SiteAtpReadinessStatus $status */
    $status = $stats['status'];
    $statusBadgeClass = match ($status) {
        SiteAtpReadinessStatus::Ready => 'badge-success',
        SiteAtpReadinessStatus::FdaRegistered => 'badge-success',
        SiteAtpReadinessStatus::Expiring => 'badge-warning',
        SiteAtpReadinessStatus::UnknownExpiry => 'badge-warning',
        SiteAtpReadinessStatus::Expired => 'badge-error',
        SiteAtpReadinessStatus::NoLicenses => 'badge-neutral',
        SiteAtpReadinessStatus::NeedsReceivingState => 'badge-neutral',
        SiteAtpReadinessStatus::NotMonitored => 'badge-neutral',
    };
    $muted = 'text-base-content/60';
    $tenantState = $stats['tenant_state'];
    $evaluationKeys = AtpLicenseRelevance::evaluationJurisdictionKeys();
    $hasFootprint = $evaluationKeys !== [];
    $jurisdictionLabel = AtpLicenseRelevance::evaluationJurisdictionsLabel();
    $atpFilterUrl = fn (string $atpStatus): string => SiteResource::getUrl('view', [
        'record' => $record,
        'relation' => 1,
        'atp_status' => $atpStatus,
    ]);

    $setReceivingStateUrl = null;
    $user = auth()->user();
    $adminPanel = Filament::getPanel('admin');

    if ($user instanceof FilamentUser && $adminPanel !== null && $user->canAccessPanel($adminPanel)) {
        $currentTenant = tenant();

        if ($currentTenant !== null) {
            $setReceivingStateUrl = TenantResource::getUrl('edit', ['record' => $currentTenant], panel: 'admin');
        }
    }

    $relevantSummary = match (true) {
        $status === SiteAtpReadinessStatus::FdaRegistered => 'All states (FDA registration)',
        $status === SiteAtpReadinessStatus::NotMonitored => 'Not monitored',
        $hasFootprint => number_format($stats['relevant_total']).' relevant for '.$jurisdictionLabel,
        default => null,
    };

    $alertChips = [];
    if ($hasFootprint && $stats['relevant_expired'] > 0) {
        $alertChips[] = [
            'label' => 'Expired '.$stats['relevant_expired'],
            'class' => 'badge-error',
            'url' => $linkableCounts ? $atpFilterUrl('expired') : null,
        ];
    }
    if ($hasFootprint && $stats['relevant_expiring_within_90_days'] > 0) {
        $alertChips[] = [
            'label' => 'Expiring '.$stats['relevant_expiring_within_90_days'],
            'class' => 'badge-warning',
            'url' => $linkableCounts ? $atpFilterUrl('expiring') : null,
        ];
    }
    if ($hasFootprint && $stats['relevant_unknown_expiry'] > 0) {
        $alertChips[] = [
            'label' => 'Unknown expiry '.$stats['relevant_unknown_expiry'],
            'class' => 'badge-warning',
            'url' => $linkableCounts ? $atpFilterUrl('unknown_expiry') : null,
        ];
    }
    $outsideExpired = $stats['expired_total'] > 0 && $stats['relevant_expired'] !== $stats['expired_total']
        ? $stats['expired_total'] - $stats['relevant_expired']
        : 0;
@endphp

@if ($wrapInCard)
    <div class="card w-full bg-base-100 shadow-xl">
        <div @class(['card-body', 'gap-3' => $compact, 'gap-4' => ! $compact])>
@endif
<section @class([
    'tp-site-atp-readiness w-full',
    'border-t border-base-300 pt-6' => ! $wrapInCard,
    'space-y-3' => ! $compact,
    'space-y-2' => $compact,
])>
    <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-xs font-bold uppercase tracking-wider text-base-content/50">ATP Readiness</h3>
        <span class="badge badge-sm {{ $statusBadgeClass }}">{{ $status->label() }}</span>
        @foreach ($stats['facility_types'] as $facilityType)
            <span class="badge badge-sm badge-neutral">{{ $facilityType->label() }}</span>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        @if ($relevantSummary !== null)
            <span class="text-base-content">{{ $relevantSummary }}</span>
        @elseif ($setReceivingStateUrl)
            <a href="{{ $setReceivingStateUrl }}" class="link link-primary font-medium">
                Set preferred receiving state
            </a>
        @else
            <span class="{{ $muted }}">Add organization sites with state/country to evaluate licenses.</span>
        @endif

        @if ($stats['total'] > 0)
            <span class="{{ $muted }}">{{ number_format($stats['total']) }} total</span>
        @endif

        @if ($tenantState !== null)
            <span class="{{ $muted }}" title="Badge label preference — license checks use all organization site jurisdictions.">
                {{ $tenantState }} preferred
            </span>
        @elseif ($relevantSummary !== null && $setReceivingStateUrl)
            <a href="{{ $setReceivingStateUrl }}" class="link link-hover text-sm">
                Set preferred receiving state
            </a>
        @endif

        @foreach ($alertChips as $chip)
            @if (filled($chip['url']))
                <a href="{{ $chip['url'] }}" class="badge badge-sm {{ $chip['class'] }} hover:opacity-80">{{ $chip['label'] }}</a>
            @else
                <span class="badge badge-sm {{ $chip['class'] }}">{{ $chip['label'] }}</span>
            @endif
        @endforeach
    </div>

    @unless ($compact)
        @if ($outsideExpired > 0)
            <p class="text-xs {{ $muted }}">
                <span class="badge badge-sm badge-neutral">{{ number_format($outsideExpired) }}</span>
                expired outside organization jurisdictions — not used for readiness.
            </p>
        @endif

        @if ($hasFootprint && $stats['relevant_unknown_expiry'] > 0)
            <p class="text-xs {{ $muted }}">No expiration date on file — cannot be shown to be in force.</p>
        @endif
    @endunless

    <details class="text-xs {{ $muted }}">
        <summary class="cursor-pointer select-none hover:text-base-content/80">About this check</summary>
        <p class="mt-1 max-w-3xl leading-5">{{ AtpDisclosure::SOURCE }}</p>
    </details>
</section>
@if ($wrapInCard)
        </div>
    </div>
@endif
