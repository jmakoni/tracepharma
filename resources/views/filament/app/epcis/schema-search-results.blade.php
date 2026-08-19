@php
    /** @var string $type */
    $type = $type ?? 'epcs';
    /** @var list<array<string, mixed>> $rows */
    $rows = $rows ?? [];
    /** @var int $total */
    $total = (int) ($total ?? count($rows));
    /** @var bool $truncated */
    $truncated = (bool) ($truncated ?? false);

    $cell = 'px-3 py-2 text-sm text-base-content align-top';
    $head = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-base-content/60 text-left';
@endphp

<div class="space-y-3" wire:key="schema-search-results-{{ $type }}-{{ $total }}">
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="badge badge-outline">
            {{ $type === 'documents' ? 'EPCIS documents (TI)' : 'Serialized units (EPCs)' }}
        </span>
        <span class="text-base-content/70">
            Showing {{ count($rows) }} of {{ number_format($total) }} match{{ $total === 1 ? '' : 'es' }}
        </span>
        @if ($truncated)
            <span class="badge badge-warning badge-outline">Results capped — refine your search for a smaller set</span>
        @endif
    </div>

    @if (count($rows) === 0)
        <div class="alert alert-warning text-sm">
            <span>No matches found. Try adjusting GTIN or lot, or switch to Advanced search.</span>
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-base-300 bg-base-100">
            <table class="table table-sm w-full min-w-[36rem]">
                <thead>
                    <tr class="border-b border-base-300 bg-base-200/60">
                        @if ($type === 'documents')
                            <th class="{{ $head }}">Date</th>
                            <th class="{{ $head }}">ASN</th>
                            <th class="{{ $head }}">PO</th>
                            <th class="{{ $head }}">Status</th>
                            <th class="{{ $head }}">Actions</th>
                        @else
                            <th class="{{ $head }} w-10">
                                <input
                                    type="checkbox"
                                    class="checkbox checkbox-sm"
                                    aria-label="Select all"
                                    @checked($this->allDisplayedEpcsSelected())
                                    wire:click="toggleSelectAllEpcs($event.target.checked)"
                                >
                            </th>
                            <th class="{{ $head }}">GTIN</th>
                            <th class="{{ $head }}">Lot</th>
                            <th class="{{ $head }}">Serial</th>
                            <th class="{{ $head }} hidden md:table-cell">Document</th>
                            <th class="{{ $head }}">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $data)
                        <tr class="border-b border-base-200 last:border-0" wire:key="search-row-{{ $type }}-{{ $data['id'] ?? $loop->index }}">
                            @if ($type === 'documents')
                                <td class="{{ $cell }} whitespace-nowrap">
                                    {{ $data['creation_date'] ?? '—' }}
                                </td>
                                <td class="{{ $cell }} font-mono text-xs">
                                    <x-copyable-identifier :value="$data['asn_number'] ?? null" title="Copy ASN" />
                                </td>
                                <td class="{{ $cell }} font-mono text-xs">
                                    <x-copyable-identifier :value="$data['customer_po'] ?? null" title="Copy PO" />
                                </td>
                                <td class="{{ $cell }}">
                                    <span class="badge badge-ghost badge-sm">{{ $data['status'] ?? '—' }}</span>
                                </td>
                                <td class="{{ $cell }} whitespace-nowrap space-x-3">
                                    @if (! empty($data['view_url']))
                                        <a href="{{ $data['view_url'] }}" class="link link-primary text-sm">View</a>
                                    @endif
                                    @if (! empty($data['id']))
                                        <button
                                            type="button"
                                            wire:click="viewUnitsFromDocument({{ (int) $data['id'] }})"
                                            class="link link-secondary text-sm"
                                        >
                                            View units
                                        </button>
                                    @endif
                                </td>
                            @else
                                <td class="{{ $cell }}">
                                    <input
                                        type="checkbox"
                                        class="checkbox checkbox-sm"
                                        value="{{ (int) ($data['id'] ?? 0) }}"
                                        wire:model.live="selectedEpcIds"
                                    >
                                </td>
                                <td class="{{ $cell }} font-mono text-xs whitespace-nowrap">
                                    @php
                                        $instanceLabel = $data['gtin14'] ?? $data['sscc18'] ?? null;
                                        $instanceScan = (filled($data['sscc18'] ?? null) ? '(00)'.$data['sscc18'] : null)
                                            ?? ((filled($data['gtin14'] ?? null) && filled($data['serial_number'] ?? null))
                                                ? '(01)'.$data['gtin14'].'(21)'.$data['serial_number']
                                                : null)
                                            ?? ($data['epc_uri'] ?? null);
                                    @endphp
                                    <x-copyable-identifier :value="$instanceLabel" :title="filled($data['gtin14'] ?? null) ? 'Copy GTIN' : 'Copy SSCC'">
                                        @if ($instanceScan)
                                            <a
                                                href="{{ \App\Filament\App\Pages\AssetTracking::getUrl(['scan' => $instanceScan]) }}"
                                            class="tp-trace-link"
                                        >{{ $instanceLabel ?? '—' }}</a>
                                        @else
                                            <span>{{ $instanceLabel ?? '—' }}</span>
                                        @endif
                                    </x-copyable-identifier>
                                </td>
                                <td class="{{ $cell }} font-mono text-xs whitespace-nowrap">
                                    <x-copyable-identifier :value="$data['lot_number'] ?? null" title="Copy lot" />
                                </td>
                                <td class="{{ $cell }} font-mono text-xs whitespace-nowrap">
                                    <div>
                                        <x-copyable-identifier :value="$data['serial_number'] ?? null" title="Copy serial" />
                                    </div>
                                    @if (! empty($data['epc_uri']))
                                        <details class="mt-0.5 max-w-[12rem] text-[10px] text-base-content/50">
                                            <summary class="cursor-pointer select-none">URI</summary>
                                            <x-copyable-identifier :value="$data['epc_uri']" title="Copy URI" class="mt-0.5 block break-all font-mono leading-snug" />
                                        </details>
                                    @endif
                                </td>
                                <td class="{{ $cell }} hidden md:table-cell">
                                    @if (! empty($data['view_url']))
                                        <a href="{{ $data['view_url'] }}" class="link link-primary text-sm">
                                            #{{ $data['document_id'] ?? '—' }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="{{ $cell }} whitespace-nowrap space-x-2">
                                    @if (! empty($data['view_url']))
                                        <a href="{{ $data['view_url'] }}" class="link link-primary text-sm md:hidden">Document</a>
                                    @endif
                                    @if (! empty($data['id']) && $this->canQuarantineEpc((int) $data['id']))
                                        <button
                                            type="button"
                                            wire:click="openQuarantineFromSearch({{ (int) $data['id'] }})"
                                            class="link link-warning text-sm"
                                        >
                                            Quarantine
                                        </button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
