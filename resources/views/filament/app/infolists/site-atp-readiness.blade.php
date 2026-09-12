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
    $sectionHeading = 'text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 border-b border-gray-200 dark:border-gray-800 pb-1 mb-2';
    $label = 'font-semibold text-gray-500 dark:text-gray-400';
    $value = 'min-w-0 text-gray-900 dark:text-gray-100';
    $muted = 'text-gray-500 dark:text-gray-400';
    $tenantState = $stats['tenant_state'];
    $evaluationKeys = AtpLicenseRelevance::evaluationJurisdictionKeys();
    $hasFootprint = $evaluationKeys !== [];
    $jurisdictionLabel = AtpLicenseRelevance::evaluationJurisdictionsLabel();
    $linkableCounts = $linkableCounts ?? false;
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
@endphp

<section class="tp-site-atp-readiness w-full">
    <h3 class="{{ $sectionHeading }}">ATP Readiness</h3>

    <p class="{{ $muted }} text-xs mb-2">{{ AtpDisclosure::SOURCE }}</p>

    <div
        class="grid items-start text-sm"
        style="grid-template-columns: minmax(11rem, auto) minmax(0, 1fr); column-gap: 0.75rem; row-gap: 0.375rem;"
    >
        <span class="{{ $label }}">Preferred receiving state</span>
        <span class="{{ $value }}">
            @if ($tenantState !== null)
                {{ $tenantState }}
                <span class="{{ $muted }} block text-xs mt-0.5">Badge label preference — license checks use all organization site jurisdictions.</span>
            @else
                @if ($setReceivingStateUrl)
                    <a href="{{ $setReceivingStateUrl }}" class="link link-primary font-medium">
                        Set preferred receiving state
                    </a>
                @else
                    <span class="{{ $muted }}">Not set (optional).</span>
                @endif
                <span class="{{ $muted }} block text-xs mt-0.5">
                    ATP readiness uses organization facility states even when this is unset.
                </span>
            @endif
        </span>

        <span class="{{ $label }}">Status</span>
        <span class="{{ $value }}">
            <span class="badge badge-sm {{ $statusBadgeClass }}">{{ $status->label() }}</span>
        </span>

        <span class="{{ $label }}">Relevant licenses</span>
        <span class="{{ $value }}">
            @if ($status === SiteAtpReadinessStatus::FdaRegistered)
                All states (FDA registration)
            @elseif ($status === SiteAtpReadinessStatus::NotMonitored)
                <span class="{{ $muted }}">Not monitored</span>
            @elseif ($hasFootprint)
                {{ number_format($stats['relevant_total']) }} for {{ $jurisdictionLabel }}
            @else
                <span class="{{ $muted }}">Add organization sites with state/country to evaluate licenses.</span>
            @endif
        </span>

        <span class="{{ $label }}">Expired (relevant)</span>
        <span class="{{ $value }}">
            @if ($hasFootprint && $stats['relevant_expired'] > 0)
                @if ($linkableCounts)
                    <a href="{{ $atpFilterUrl('expired') }}" class="badge badge-sm badge-error hover:opacity-80">
                        {{ number_format($stats['relevant_expired']) }}
                    </a>
                @else
                    <span class="badge badge-sm badge-error">{{ number_format($stats['relevant_expired']) }}</span>
                @endif
            @elseif ($hasFootprint)
                <span class="{{ $muted }}">0</span>
            @else
                <span class="{{ $muted }}">—</span>
            @endif
        </span>

        <span class="{{ $label }}">Expiring (90d, relevant)</span>
        <span class="{{ $value }}">
            @if ($hasFootprint && $stats['relevant_expiring_within_90_days'] > 0)
                @if ($linkableCounts)
                    <a href="{{ $atpFilterUrl('expiring') }}" class="badge badge-sm badge-warning hover:opacity-80">
                        {{ number_format($stats['relevant_expiring_within_90_days']) }}
                    </a>
                @else
                    <span class="badge badge-sm badge-warning">{{ number_format($stats['relevant_expiring_within_90_days']) }}</span>
                @endif
            @elseif ($hasFootprint)
                <span class="{{ $muted }}">0</span>
            @else
                <span class="{{ $muted }}">—</span>
            @endif
        </span>

        <span class="{{ $label }}">Unknown expiry (relevant)</span>
        <span class="{{ $value }}">
            @if ($hasFootprint && $stats['relevant_unknown_expiry'] > 0)
                @if ($linkableCounts)
                    <a href="{{ $atpFilterUrl('unknown_expiry') }}" class="badge badge-sm badge-warning hover:opacity-80">
                        {{ number_format($stats['relevant_unknown_expiry']) }}
                    </a>
                @else
                    <span class="badge badge-sm badge-warning">{{ number_format($stats['relevant_unknown_expiry']) }}</span>
                @endif
                <span class="{{ $muted }} ml-1">No expiration date on file — cannot be shown to be in force.</span>
            @elseif ($hasFootprint)
                <span class="{{ $muted }}">0</span>
            @else
                <span class="{{ $muted }}">—</span>
            @endif
        </span>

        <span class="{{ $label }}">Total licenses</span>
        <span class="{{ $value }}">{{ number_format($stats['total']) }}</span>

        @if ($stats['expired_total'] > 0 && ($stats['relevant_expired'] !== $stats['expired_total']))
            <span class="{{ $label }}">Expired (outside footprint)</span>
            <span class="{{ $value }}">
                <span class="badge badge-sm badge-neutral">{{ number_format($stats['expired_total'] - $stats['relevant_expired']) }}</span>
                <span class="{{ $muted }} ml-1">Outside organization site jurisdictions — not used for readiness.</span>
            </span>
        @endif

        <span class="{{ $label }}">Facility types</span>
        <span class="{{ $value }}">
            @if ($stats['facility_types']->isEmpty())
                <span class="{{ $muted }}">—</span>
            @else
                <span class="flex flex-wrap gap-1.5">
                    @foreach ($stats['facility_types'] as $facilityType)
                        <span class="badge badge-sm badge-neutral">{{ $facilityType->label() }}</span>
                    @endforeach
                </span>
            @endif
        </span>
    </div>
</section>
