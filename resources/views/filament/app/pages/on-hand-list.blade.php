<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                    <label class="form-control gap-1 flex-1 max-w-xl">
                        <span class="label-text text-sm font-medium">Site</span>
                        <select wire:model.live="siteId" class="select select-bordered">
                            @foreach ($this->siteOptions() as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($this->supportsPrincipalFilter())
                        <label class="form-control gap-1 flex-1 max-w-xl">
                            <span class="label-text text-sm font-medium">Principal</span>
                            <select wire:model.live="principalId" class="select select-bordered">
                                <option value="">All principals</option>
                                @foreach ($this->principalOptions() as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <form wire:submit="goToAssetTracking" class="flex flex-1 gap-2 items-end max-w-2xl">
                        <label class="form-control gap-1 flex-1">
                            <span class="label-text text-sm font-medium">Find</span>
                            <input
                                type="text"
                                wire:model="scanInput"
                                class="input input-bordered font-mono"
                                placeholder="Scan or paste SSCC, GTIN+serial, URN, or Digital Link"
                                autocomplete="off"
                                inputmode="none"
                            />
                        </label>
                        <button type="submit" class="btn btn-primary">Trace</button>
                    </form>
                </div>

                @if ($activeTab === 'lots' && $filterGtin === null && trim($scanInput) === '')
                    <p class="text-sm opacity-70 -mt-1">
                        Scan or paste an identifier above to open Asset Tracking, or browse lot rollups below.
                    </p>
                @endif

                <div role="tablist" class="tabs tabs-boxed flex-wrap gap-1 bg-base-200 p-1 rounded-box w-fit">
                    @foreach ([
                        'lots' => 'Lots',
                        'serials' => 'Serials',
                        'expiry' => 'Near-expiry',
                        'holds' => 'Holds',
                        'investigate' => 'Investigate',
                    ] as $key => $label)
                        <button
                            type="button"
                            role="tab"
                            wire:click="setActiveTab('{{ $key }}')"
                            class="tab {{ $activeTab === $key ? 'tab-active' : '' }}"
                            aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>

                @if ($activeTab === 'lots')
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Lot</th>
                                    <th>Expiry</th>
                                    <th class="text-end">Pickable</th>
                                    <th class="text-end">On hold</th>
                                    <th class="text-end">SGTIN</th>
                                    <th class="text-end">SSCC</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->lotRows() as $row)
                                    <tr wire:key="lot-{{ $row['gtin14'] }}-{{ $row['lot_number'] }}">
                                        <td>
                                            @php($display = $this->productDisplay($row))
                                            <div class="text-sm font-medium">{{ $display['name'] }}</div>
                                            @if ($display['volume'])
                                                <div class="text-xs opacity-70">{{ $display['volume'] }}</div>
                                            @endif
                                            @if ($display['ndc'])
                                                <div class="font-mono text-xs opacity-60">NDC {{ $display['ndc'] }}</div>
                                            @endif
                                        </td>
                                        <td>{{ $row['lot_number'] !== '' ? $row['lot_number'] : '—' }}</td>
                                        <td>
                                            @if ($row['min_expiry'])
                                                {{ $row['min_expiry'] }}
                                                @if ($row['max_expiry'] && $row['max_expiry'] !== $row['min_expiry'])
                                                    <span class="opacity-60">– {{ $row['max_expiry'] }}</span>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="text-end font-medium">{{ $row['pickable'] }}</td>
                                        <td class="text-end">
                                            @if ($row['hold_count'] > 0)
                                                <span class="badge badge-error badge-sm">{{ $row['hold_count'] }}</span>
                                            @else
                                                <span class="opacity-50">0</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ $row['sgtin_count'] }}</td>
                                        <td class="text-end">{{ $row['sscc_count'] }}</td>
                                        <td class="flex flex-wrap gap-1">
                                            @if ($row['has_quarantine'])
                                                <span class="badge badge-error badge-sm">Quarantine</span>
                                            @endif
                                            @if ($row['has_near_expiry'])
                                                <span class="badge badge-warning badge-sm">Near expiry</span>
                                            @endif
                                            @if (! $row['has_quarantine'] && ! $row['has_near_expiry'])
                                                <span class="opacity-50 text-sm">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn btn-ghost btn-xs"
                                                wire:click="openLotSerials({{ \Illuminate\Support\Js::from($row['gtin14']) }}, {{ \Illuminate\Support\Js::from($row['lot_number']) }})"
                                            >Serials</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-sm opacity-70">No on-hand lots at this site.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div>
                        {{ $this->lotRows()->links() }}
                    </div>
                    <p class="text-sm opacity-70">
                        Unpacked children after break-pack live on
                        <a href="{{ \App\Filament\App\Pages\UnpackedItems::getUrl(panel: 'app') }}" class="link">Unpacked items</a>.
                        Compliance FEFO also on
                        <a href="{{ \App\Filament\App\Pages\ExpiryWorklist::getUrl(panel: 'app') }}" class="link">Expiry worklist</a>.
                    </p>
                @endif

                @if ($activeTab === 'serials')
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="label cursor-pointer gap-2 py-0">
                            <input type="checkbox" class="toggle toggle-sm" wire:model.live="showContents" />
                            <span class="label-text text-sm">Show contents</span>
                        </label>
                        @unless ($showContents || $filterGtin !== null)
                            <span class="text-xs opacity-60">Default: outermost parents (pickable). Open holds stay on Holds.</span>
                        @endunless
                    </div>
                    @if ($filterGtin !== null)
                        <div class="flex items-center gap-2 text-sm">
                            <span class="opacity-70">Filtered:</span>
                            <span class="font-mono">{{ $this->productLabel(['gtin14' => $filterGtin]) }}</span>
                            <span>·</span>
                            <span>{{ $filterLot !== '' ? $filterLot : '(no lot)' }}</span>
                            <button type="button" class="btn btn-ghost btn-xs" wire:click="clearSerialFilters">Clear</button>
                        </div>
                    @endif
                    {{ $this->content }}
                @endif

                @if ($activeTab === 'expiry')
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label class="form-control gap-1">
                            <span class="label-text text-sm font-medium">Window</span>
                            <select wire:model.live="windowDays" class="select select-bordered">
                                <option value="30">30 days</option>
                                <option value="60">60 days</option>
                                <option value="90">90 days</option>
                            </select>
                        </label>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Identifier</th>
                                    <th>Lot</th>
                                    <th>Expiry</th>
                                    <th>Days</th>
                                    @if ($this->canQuarantine())
                                        <th></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->expiryRows() as $epc)
                                    <tr wire:key="expiry-{{ $epc->getKey() }}">
                                        <td class="font-mono text-sm">
                                            <a href="{{ \App\Support\Tracing\AssetTrackingUrl::forEpc($epc) }}" class="link">{{ $this->identifier($epc) }}</a>
                                        </td>
                                        <td>{{ $epc->ilmd?->lot_number ?? '—' }}</td>
                                        <td>{{ $epc->ilmd?->expiry_date?->toDateString() ?? '—' }}</td>
                                        <td>{{ $this->daysLeft($epc) ?? '—' }}</td>
                                        @if ($this->canQuarantine())
                                            <td>
                                                <button type="button" class="btn btn-error btn-xs" wire:click="quarantineExpiryHit({{ $epc->getKey() }})">Quarantine</button>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-sm opacity-70">No near-expiry on-hand serials in this window.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($activeTab === 'holds')
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Identifier</th>
                                    <th>Reason</th>
                                    <th>Opened</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->holdRows() as $hold)
                                    <tr wire:key="hold-{{ $hold->getKey() }}">
                                        <td class="font-mono text-sm">
                                            @if ($hold->epc)
                                                <a href="{{ \App\Support\Tracing\AssetTrackingUrl::forEpc($hold->epc) }}" class="link">{{ $this->identifier($hold->epc) }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $hold->reason }}</td>
                                        <td>{{ $hold->opened_at?->toDateTimeString() ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-sm opacity-70">No open quarantine holds on on-hand stock at this site.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="text-sm opacity-70">
                        Manage holds on
                        <a href="{{ \App\Filament\App\Pages\Quarantine::getUrl(panel: 'app') }}" class="link">Quarantine</a>.
                    </p>
                @endif

                @if ($activeTab === 'investigate')
                    <label class="form-control gap-1">
                        <span class="label-text text-sm font-medium">Paste identifiers (one per line)</span>
                        <textarea wire:model="investigatePaste" class="textarea textarea-bordered font-mono min-h-32" placeholder="(01)…(21)…&#10;00…"></textarea>
                    </label>
                    <button type="button" class="btn btn-primary w-fit" wire:click="runInvestigate">Classify</button>
                    @if ($investigateResults !== [])
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Scan</th>
                                        <th>Status</th>
                                        <th>Identifier</th>
                                        <th>Site</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($investigateResults as $result)
                                        <tr>
                                            <td class="font-mono text-xs">{{ $result['scan'] }}</td>
                                            <td>
                                                <span @class([
                                                    'badge badge-sm',
                                                    'badge-success' => $result['status'] === 'on_hand',
                                                    'badge-warning' => in_array($result['status'], ['other_site', 'quarantined'], true),
                                                    'badge-ghost' => in_array($result['status'], ['not_found', 'unknown'], true),
                                                ])>{{ str_replace('_', ' ', $result['status']) }}</span>
                                            </td>
                                            <td class="font-mono text-sm">{{ $result['identifier'] }}</td>
                                            <td>{{ $result['site_label'] ?? '—' }}</td>
                                            <td>
                                                @if ($result['asset_url'])
                                                    <a href="{{ $result['asset_url'] }}" class="link text-sm">Trace</a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
