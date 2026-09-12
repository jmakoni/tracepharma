@props([
    'rows' => [],
    'title' => 'Confirmed',
    'empty' => 'Scan barcodes to build the list.',
    'canRemove' => true,
    'removeMethod' => 'removeConfirmed',
    'idKey' => 'line_id',
    'actionsVariant' => 'delete',
])

@php
    $rowList = $rows instanceof \Illuminate\Support\Collection ? $rows : collect($rows);
    $isStatusVariant = $actionsVariant === 'status';
@endphp

<div class="card bg-base-100 shadow-xl">
    <div class="card-body gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="card-title text-base">{{ $title }}</h2>
            {{ $headerActions ?? '' }}
        </div>

        @if ($rowList->isEmpty())
            <p class="text-sm opacity-70">{{ $empty }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Identifier</th>
                            <th>Scan time</th>
                            <th>Transcoded Value</th>
                            <th class="text-right">{{ $isStatusVariant ? 'Status' : 'Actions' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rowList as $row)
                            @php
                                $present = (bool) ($row['present'] ?? true);
                                $rowId = (int) ($row[$idKey] ?? 0);
                            @endphp
                            <tr>
                                <td class="font-mono text-sm whitespace-nowrap">{{ $row['identifier'] ?? '—' }}</td>
                                <td class="text-sm whitespace-nowrap">{{ $row['scanned_at'] ?? '—' }}</td>
                                <td class="font-mono text-sm break-all">{{ $row['urn'] ?? '—' }}</td>
                                <td class="text-right">
                                    @if ($isStatusVariant)
                                        <span @class(['badge badge-outline', $row['status_badge_class'] ?? 'badge-ghost'])>
                                            {{ $row['status_label'] ?? '—' }}
                                        </span>
                                    @else
                                        <div class="inline-flex items-center justify-end gap-1">
                                            @if ($present)
                                                <span class="inline-flex text-success" title="Present" aria-label="Present">
                                                    <x-filament::icon icon="heroicon-o-check-circle" class="size-5" />
                                                </span>
                                            @else
                                                <span class="inline-flex text-error" title="Not present" aria-label="Not present">
                                                    <x-filament::icon icon="heroicon-o-x-circle" class="size-5" />
                                                </span>
                                            @endif

                                            @if ($canRemove && $present && $rowId > 0)
                                                <button
                                                    type="button"
                                                    class="btn btn-ghost btn-square btn-xs"
                                                    wire:click="{{ $removeMethod }}({{ $rowId }})"
                                                    aria-label="Delete"
                                                >
                                                    <x-filament::icon
                                                        icon="heroicon-o-trash"
                                                        class="size-4"
                                                    />
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
