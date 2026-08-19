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

    $muted = 'text-gray-500 dark:text-gray-400';
    $isAppPanel = filament()->getCurrentPanel()?->getId() === 'app';
    $tradingPartner = ($record instanceof Site && filled($record->tradingPartner))
        ? $record->tradingPartner
        : null;
@endphp

@if ($record)
    <div @class(['tp-site-profile w-full', 'space-y-4' => ! $compact, 'space-y-2' => $compact])>
        <div class="space-y-2">
            <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                {{ $heading }}
            </h2>

            @if (filled($siteName) && $siteName !== $heading && ! str_starts_with($heading, $siteName.' -'))
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $siteName }}
                </p>
            @endif

            @if (! $compact && filled($displayCode))
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Code {{ $displayCode }}
                </p>
            @endif

            @if ($tradingPartner && $isAppPanel)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Partner:
                    <a
                        href="{{ TradingPartnerResource::getUrl('view', ['record' => $tradingPartner]) }}"
                        class="link link-hover font-medium text-gray-700 dark:text-gray-200"
                    >
                        {{ DisplayName::clean($tradingPartner->name) }}
                    </a>
                </p>
            @endif

            <p class="text-sm text-gray-500 dark:text-gray-400 font-mono flex items-center gap-1.5">
                <span>GLN {{ $val($record->gln) }}</span>
                @if (filled($record->gln))
                    <button
                        type="button"
                        x-data
                        x-on:click.prevent.stop="window.navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($record->gln) }}); $tooltip('Copied', { theme: $store.theme, timeout: 2000 })"
                        class="inline-flex text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                        title="Copy GLN"
                    >
                        <x-filament::icon icon="heroicon-m-clipboard" class="h-3.5 w-3.5" />
                    </button>
                @endif
            </p>

            <div class="flex flex-wrap items-center gap-2 pt-1">
                @if ($record->is_headquarters)
                    <span class="badge badge-sm badge-primary">HQ</span>
                @endif
                <span class="badge badge-sm {{ $statusBadge }}">{{ $statusLabel }}</span>
            </div>
        </div>

        @unless ($compact)
            <hr class="border-t border-gray-200 dark:border-gray-800">

            <section class="min-w-0 space-y-0.5 text-sm text-gray-900 dark:text-gray-100">
                @forelse ($addressLines as $line)
                    <p>{{ $line }}</p>
                @empty
                    <p class="{{ $muted }}">{{ $dash }}</p>
                @endforelse
                @if (filled($timezoneLine))
                    <p class="pt-1 text-gray-700 dark:text-gray-300">{{ $timezoneLine }}</p>
                @else
                    <p class="pt-1 {{ $muted }}">Timezone: {{ $dash }}</p>
                @endif
            </section>
        @endunless
    </div>
@endif
