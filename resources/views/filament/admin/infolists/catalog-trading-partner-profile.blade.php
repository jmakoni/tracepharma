@php
    /** @var \App\Models\TradingPartner|null $record */
    $record = $record ?? null;
    $dash = '—';
    $val = static function (mixed $value) use ($dash): string {
        if ($value === null) {
            return $dash;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : $dash;
    };
    $typeLabel = $record?->partner_type?->label() ?? $dash;
    $statusLabel = $record?->is_active ? 'Active' : 'Inactive';
    $statusBadge = $record?->is_active ? 'badge-success' : 'badge-ghost';

    $displayName = \App\Support\Catalog\DisplayName::clean($record?->name);
    $displayDba = \App\Support\Catalog\DisplayName::clean($record?->doing_business_as);

    $addressLines = $record
        ? \App\Support\Catalog\PartnerLocationDisplay::addressLines($record)
        : [];
    $timezoneLine = $record
        ? \App\Support\Catalog\PartnerLocationDisplay::timezoneLine(
            \App\Support\Catalog\PartnerLocationDisplay::resolveTimezone($record)
        )
        : null;

    $identityRows = [];
    if ($record) {
        $identityRows[] = ['label' => 'GLN', 'value' => filled($record->gln) ? trim((string) $record->gln) : null];
        foreach ([
            ['SGLN', $record->sgln],
            ['DEA', $record->dea_number],
            ['DUNS', $record->duns_number],
            ['HIN', $record->hin_number],
            ['Chemical Reg', $record->chemical_reg_number],
        ] as [$label, $value]) {
            $trimmed = is_string($value) ? trim($value) : '';
            if ($trimmed === '') {
                continue;
            }
            $identityRows[] = ['label' => $label, 'value' => $trimmed];
        }
    }
@endphp

@if ($record)
    <div class="card w-full bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="tp-partner-profile w-full space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0 space-y-1">
                        <h2 class="text-2xl font-bold tracking-tight text-base-content">
                            {{ $displayName }}
                        </h2>

                        @if (filled($displayDba))
                            <p class="text-sm text-base-content/60">
                                DBA {{ $displayDba }}
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="badge badge-sm badge-neutral">{{ $typeLabel }}</span>
                        <span class="badge badge-sm {{ $statusBadge }}">{{ $statusLabel }}</span>
                    </div>
                </div>

                @if ($identityRows !== [])
                    <div class="flex flex-wrap items-center gap-2" aria-label="Partner identifiers">
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
        </div>
    </div>
@endif
