<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @if (\App\Support\Gs1\Gs1IdentityStatus::identityIncomplete())
            @include('filament.app.partials.identity-incomplete-banner')
        @endif
        <div class="alert">
            <span>
                Profile-gated operations for this tenant. Scan a barcode to jump to Receive, Asset Tracking, Verify, or Find / Recall.
            </span>
        </div>

        <div
            class="card bg-base-100 shadow-xl"
            x-data
            x-init="$nextTick(() => $refs.hubScanInput?.focus())"
            x-on:focus-hub-scan.window="$nextTick(() => $refs.hubScanInput?.focus())"
        >
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Scan to route</h2>
                <form wire:submit.prevent="routeHubScan" class="flex flex-col gap-4">
                    <div class="form-control w-full gap-1.5">
                        <label for="hub-scan-input" class="label-text text-sm font-medium">
                            Scan barcode
                        </label>
                        <div class="flex w-full items-stretch gap-2">
                            <input
                                id="hub-scan-input"
                                type="text"
                                wire:model="hubScan"
                                x-ref="hubScanInput"
                                autocomplete="off"
                                class="tp-scan-input min-h-14 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-base shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600/20 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-primary-500"
                                placeholder="SSCC, SGTIN, or other identifier"
                            />
                            <button type="submit" class="btn btn-primary btn-lg min-h-14 w-auto shrink-0 px-4">
                                Go
                            </button>
                        </div>
                        <p class="text-sm opacity-70">
                            SSCC opens an active Receive session, in-transit transfer receive, or a unique ASN match; then a single open session; otherwise a shippable scan opens Ship Order, or Asset Tracking. SGTIN opens a Receive session using the same preference order — on-session match, in-transit transfer receive, or a unique ASN match, then a single open session — otherwise a shippable scan opens Ship Order, or Asset Tracking. Other scans open Find / Recall when available.
                        </p>
                    </div>
                </form>
            </div>
        </div>

        @if (filled($featureMap = $this->featureMap()))
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title text-base">Operations features</h2>
                    <div class="stats stats-vertical lg:stats-horizontal w-full shadow">
                        @foreach ($featureMap as $label => $enabled)
                            <div class="stat">
                                <div class="stat-title">{{ $label }}</div>
                                <div class="stat-value text-base">
                                    <span class="badge badge-success badge-outline">enabled</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @if (($openWork = $this->openWorkItems())->isNotEmpty())
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="card-title text-base">Open work</h2>
                        @if ($url = $this->resourceIndexUrl(\App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource::class))
                            <a href="{{ $url }}" class="btn btn-ghost btn-sm">View receive</a>
                        @endif
                    </div>
                    <p class="text-sm opacity-70">
                        Unsubmitted sessions at your selected site (up to 10). Resume on desktop or floor.
                    </p>
                    <ul class="menu bg-base-200 rounded-box w-full">
                        @foreach ($openWork as $item)
                            <li>
                                <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div class="font-medium">
                                            {{ $item['label'] }}
                                            · #{{ $item['id'] }}
                                        </div>
                                        <div class="text-sm opacity-70 font-normal">
                                            {{ $item['detail'] }}
                                            @if ($item['opened_at'])
                                                · opened {{ $item['opened_at']->diffForHumans() }}
                                            @endif
                                        </div>
                                    </div>
                                    @if (filled($item['url']))
                                        <a href="{{ $item['url'] }}" class="btn btn-primary btn-sm gap-2">
                                            <x-filament::icon
                                                icon="heroicon-o-arrow-top-right-on-square"
                                                class="h-4 w-4"
                                            />
                                            Open
                                        </a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @if (filled($directories = $this->directories()))
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title text-base">Directories</h2>
                    <p class="text-sm opacity-70">
                        Jump to enabled operations and cross-partner lookup. Partner-first navigation stays in the sidebar.
                    </p>
                    <ul class="menu bg-base-200 rounded-box w-full">
                        @foreach ($directories as $directory)
                            <li>
                                <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div class="font-medium">{{ $directory['label'] }}</div>
                                        <div class="text-sm opacity-70 font-normal">
                                            {{ $directory['description'] }}
                                        </div>
                                    </div>
                                    @if (filled($directory['url']))
                                        <a href="{{ $directory['url'] }}" class="btn btn-primary btn-sm gap-2">
                                            <x-filament::icon
                                                icon="heroicon-o-arrow-top-right-on-square"
                                                class="h-4 w-4"
                                            />
                                            Open
                                        </a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
