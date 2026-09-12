<x-filament-panels::page>
    @php
        $sections = $this->sections();
    @endphp

    <div class="flex flex-col gap-4">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="card-title text-base">Range</h2>
                        <p class="text-sm opacity-70">
                            {{ $this->rangeLabel() }} · as of {{ $this->asOfLabel() }}
                        </p>
                    </div>
                    <div class="join">
                        <button
                            type="button"
                            wire:click="$set('range', 'mtd')"
                            class="btn join-item btn-sm {{ $range === 'mtd' ? 'btn-primary' : 'btn-ghost' }}"
                        >
                            MTD
                        </button>
                        <button
                            type="button"
                            wire:click="$set('range', '7')"
                            class="btn join-item btn-sm {{ $range === '7' ? 'btn-primary' : 'btn-ghost' }}"
                        >
                            7 days
                        </button>
                        <button
                            type="button"
                            wire:click="$set('range', '30')"
                            class="btn join-item btn-sm {{ $range === '30' ? 'btn-primary' : 'btn-ghost' }}"
                        >
                            30 days
                        </button>
                    </div>
                </div>
            </div>
        </div>

        @forelse ($sections as $section)
            @php
                $key = $section['key'];
                $summary = $section['data']['summary'] ?? [];
                $rows = $section['data']['rows'] ?? [];
                $percent = $this->summaryPercent($summary);
                $displayKeys = $rows !== [] ? $this->rowDisplayKeys($rows[0]) : [];
            @endphp

            <section class="card bg-base-100 shadow-xl" wire:key="leadership-dscsa-{{ $key }}">
                <div class="card-body gap-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="card-title text-base">{{ $section['label'] }}</h2>
                            <p class="text-sm opacity-70">{{ $section['description'] }}</p>
                        </div>
                        @if (filled($section['index_url']))
                            <a href="{{ $section['index_url'] }}" class="btn btn-ghost btn-sm">
                                {{ $section['index_label'] ?? 'Open' }}
                            </a>
                        @endif
                    </div>

                    <div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
                        @if ($percent !== null)
                            @php
                                $percentClass = $percent >= 95
                                    ? 'text-success'
                                    : ($percent >= 80 ? 'text-warning' : 'text-error');
                                $ok = $this->summaryCount($summary, 'sent', 'received');
                                $scored = $this->summaryCount($summary, 'total_scored');
                            @endphp
                            <div class="stat">
                                <div class="stat-title">Success rate</div>
                                <div class="stat-value {{ $percentClass }} text-3xl">
                                    {{ number_format($percent, 1) }}%
                                </div>
                                @if ($ok !== null && $scored !== null)
                                    <div class="stat-desc">
                                        {{ number_format($ok) }} / {{ number_format($scored) }}
                                    </div>
                                @endif
                            </div>
                            @if (($failed = $this->summaryCount($summary, 'failed')) !== null)
                                <div class="stat">
                                    <div class="stat-title">Failed</div>
                                    <div class="stat-value text-error text-2xl">{{ number_format($failed) }}</div>
                                </div>
                            @endif
                        @elseif ($key === 'late_missing_mdn')
                            <div class="stat">
                                <div class="stat-title">Missing MDN (pending)</div>
                                <div class="stat-value text-error text-2xl">
                                    {{ number_format((int) ($summary['missing_mdn_pending'] ?? 0)) }}
                                </div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Late MDN (pending)</div>
                                <div class="stat-value text-warning text-2xl">
                                    {{ number_format((int) ($summary['late_mdn_pending'] ?? 0)) }}
                                </div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Open MISSING_MDN cases</div>
                                <div class="stat-value text-2xl">
                                    {{ number_format((int) ($summary['open_missing_mdn_cases'] ?? 0)) }}
                                </div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Open LATE_MDN cases</div>
                                <div class="stat-value text-2xl">
                                    {{ number_format((int) ($summary['open_late_mdn_cases'] ?? 0)) }}
                                </div>
                            </div>
                        @elseif ($key === 'decommission_by_reason')
                            <div class="stat">
                                <div class="stat-title">Total events</div>
                                <div class="stat-value text-2xl">{{ number_format((int) ($summary['total'] ?? 0)) }}</div>
                            </div>
                            @foreach (($summary['reasons'] ?? []) as $reasonRow)
                                <div class="stat">
                                    <div class="stat-title">{{ str((string) ($reasonRow['reason'] ?? 'unknown'))->headline() }}</div>
                                    <div class="stat-value text-2xl">{{ number_format((int) ($reasonRow['count'] ?? 0)) }}</div>
                                </div>
                            @endforeach
                        @elseif ($key === 'stuck_serials')
                            <div class="stat">
                                <div class="stat-title">Stuck EPCs</div>
                                <div class="stat-value text-2xl">{{ number_format((int) ($summary['total_epcs'] ?? 0)) }}</div>
                            </div>
                            @foreach (($summary['by_status'] ?? []) as $statusRow)
                                <div class="stat">
                                    <div class="stat-title">{{ str((string) ($statusRow['status'] ?? ''))->headline() }}</div>
                                    <div class="stat-value text-2xl">{{ number_format((int) ($statusRow['epc_count'] ?? 0)) }}</div>
                                </div>
                            @endforeach
                        @elseif ($key === 'open_exceptions_by_code')
                            <div class="stat">
                                <div class="stat-title">Open cases</div>
                                <div class="stat-value text-2xl">{{ number_format((int) ($summary['total'] ?? 0)) }}</div>
                            </div>
                            @foreach (($summary['by_code'] ?? []) as $codeRow)
                                <div class="stat">
                                    <div class="stat-title">{{ $codeRow['code'] ?? '—' }}</div>
                                    <div class="stat-value text-2xl">{{ number_format((int) ($codeRow['count'] ?? 0)) }}</div>
                                </div>
                            @endforeach
                        @elseif ($key === 'l3_l4_ingest_lag')
                            @php
                                $overSla = (int) ($summary['over_sla_count'] ?? 0);
                            @endphp
                            <div class="stat">
                                <div class="stat-title">Max lag (hours)</div>
                                <div class="stat-value text-2xl">
                                    {{ $summary['max_lag_hours'] !== null ? number_format((float) $summary['max_lag_hours'], 2) : '—' }}
                                </div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Avg lag (hours)</div>
                                <div class="stat-value text-2xl">
                                    {{ $summary['avg_lag_hours'] !== null ? number_format((float) $summary['avg_lag_hours'], 2) : '—' }}
                                </div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Over SLA ({{ (int) ($summary['sla_hours'] ?? 4) }}h)</div>
                                <div class="stat-value {{ $overSla > 0 ? 'text-error' : '' }} text-2xl">
                                    {{ number_format($overSla) }}
                                </div>
                            </div>
                        @endif
                    </div>

                    @if ($rows === [])
                        <p class="text-sm opacity-70">No drill-down rows in this range.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        @foreach ($displayKeys as $column)
                                            <th>{{ str($column)->headline()->toString() }}</th>
                                        @endforeach
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        @php
                                            $link = $this->rowLink($row);
                                        @endphp
                                        <tr wire:key="leadership-dscsa-{{ $key }}-{{ $loop->index }}">
                                            @foreach ($displayKeys as $column)
                                                <td>
                                                    @php
                                                        $value = $row[$column] ?? '';
                                                    @endphp
                                                    @if (is_numeric($value) && ! in_array($column, ['label', 'name', 'code', 'reason', 'status', 'partner', 'site', 'gtin14', 'event_id', 'age_bucket', 'row_type', 'mdn_status', 'transmission_status'], true))
                                                        {{ number_format((float) $value, is_float($value + 0) && floor((float) $value) != (float) $value ? 1 : 0) }}
                                                    @else
                                                        {{ is_scalar($value) ? $value : json_encode($value) }}
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="text-right">
                                                @if ($link !== null)
                                                    <a href="{{ $link['url'] }}" class="btn btn-ghost btn-xs">
                                                        {{ $link['label'] }}
                                                    </a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        @empty
            <div class="alert">
                <span>No leadership DSCSA metrics are available for this organization or role.</span>
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
