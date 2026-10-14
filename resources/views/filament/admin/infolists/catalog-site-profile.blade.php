@php
    use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
    use App\Models\Site;
    use App\Support\Catalog\DisplayName;

    /** @var \App\Models\Site|null $record */
    $record = $record ?? null;
    $compact = $compact ?? false;
    $dash = '—';
    $val = static function (mixed $value) use ($dash): string {
        if ($value === null) {
            return $dash;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : $dash;
    };

    $statusLabel = $record?->is_active ? 'Active' : 'Inactive';
    $statusBadge = $record?->is_active ? 'badge-success' : 'badge-ghost';

    $heading = \App\Support\Catalog\SiteDisplayTitle::make($record);
    $siteName = \App\Support\Catalog\DisplayName::clean($record?->name);
    $displayCode = filled($record?->code)
        ? \App\Support\Catalog\DisplayName::clean(trim((string) $record->code))
        : null;

    $addressLines = $record
        ? \App\Support\Catalog\PartnerLocationDisplay::addressLines($record)
        : [];
    $timezoneLine = $record
        ? \App\Support\Catalog\PartnerLocationDisplay::timezoneLine(
            \App\Support\Catalog\PartnerLocationDisplay::resolveTimezone($record)
        )
        : null;

    $muted = 'text-sm text-base-content/60';
    $isAppPanel = filament()->getCurrentPanel()?->getId() === 'app';
    $tradingPartner = ($record instanceof Site && filled($record->tradingPartner))
        ? $record->tradingPartner
        : null;
    $partnerName = $tradingPartner
        ? DisplayName::clean($tradingPartner->name)
        : null;
    $headingAlreadyNamesPartner = filled($partnerName)
        && filled($heading)
        && str_starts_with($heading, $partnerName);
    $showSiteName = filled($siteName)
        && $siteName !== $heading
        && ! str_starts_with($heading, $siteName.' -');
    $identityRows = $record instanceof Site
        ? \App\Support\Catalog\SiteIdentityIdentifiers::rows($record)
        : [];
    $wrapInCard = $wrapInCard ?? false;
@endphp

@if ($record)
    @if ($wrapInCard)
        <div class="card w-full bg-base-100 shadow-xl">
            <div @class(['card-body', 'gap-4' => ! $compact, 'gap-3' => $compact])>
    @endif
    <div @class(['tp-site-profile w-full', 'space-y-4' => ! $compact, 'space-y-3' => $compact])>
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="min-w-0 space-y-1">
                <h2 class="text-2xl font-bold tracking-tight text-base-content">
                    {{ $heading }}
                </h2>

                @if ($showSiteName || (! $compact && filled($displayCode)) || ($tradingPartner && $isAppPanel && ! $headingAlreadyNamesPartner))
                    <p class="{{ $muted }}">
                        @if ($showSiteName)
                            {{ $siteName }}
                        @endif
                        @if ($showSiteName && ! $compact && filled($displayCode))
                            <span class="text-base-content/30"> · </span>
                        @endif
                        @if (! $compact && filled($displayCode))
                            Code {{ $displayCode }}
                        @endif
                        @if ($tradingPartner && $isAppPanel && ! $headingAlreadyNamesPartner)
                            @if ($showSiteName || (! $compact && filled($displayCode)))
                                <span class="text-base-content/30"> · </span>
                            @endif
                            <a
                                href="{{ TradingPartnerResource::getUrl('view', ['record' => $tradingPartner]) }}"
                                class="link link-hover font-medium text-base-content/80"
                            >
                                {{ $partnerName }}
                            </a>
                        @endif
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @if ($record->is_headquarters)
                    <span class="badge badge-sm badge-primary">HQ</span>
                @endif
                <span class="badge badge-sm {{ $statusBadge }}">{{ $statusLabel }}</span>
            </div>
        </div>

        @if ($identityRows !== [])
            <div class="flex flex-wrap items-center gap-2" aria-label="Site identifiers">
                @foreach ($identityRows as $identifier)
                    <span class="inline-flex max-w-full items-center gap-1.5 rounded-md bg-base-200 px-2 py-1 text-xs leading-5">
                        <span class="shrink-0 font-semibold uppercase tracking-wide text-base-content/50">{{ $identifier['label'] }}</span>
                        <x-copyable-identifier
                            :value="$val($identifier['value'])"
                            :title="'Copy '.$identifier['label']"
                            class="min-w-0 break-all text-sm text-base-content"
                        />
                    </span>
                @endforeach
            </div>
        @endif

        <section class="min-w-0 space-y-0.5 text-sm text-base-content" aria-label="Physical address">
            @forelse ($addressLines as $line)
                <p>{{ $line }}</p>
            @empty
                <p class="text-base-content/60">{{ $dash }}</p>
            @endforelse
            @if (filled($timezoneLine))
                <p class="pt-1 text-base-content/80">{{ $timezoneLine }}</p>
            @else
                <p class="pt-1 text-base-content/60">Timezone: {{ $dash }}</p>
            @endif
        </section>
    </div>
    @if ($wrapInCard)
            </div>
        </div>
    @endif
@endif
