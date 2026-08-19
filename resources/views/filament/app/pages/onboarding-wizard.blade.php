<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert">
            <span>
                {{ $this->bannerText() }}
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="card-title text-base">Progress</h2>
                        <p class="text-sm opacity-70">
                            Critical items are company GLN plus default receive
                            @if (\App\Support\TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations())
                                and ship-from sites
                            @else
                                site
                            @endif
                            with GLNs.
                        </p>
                    </div>
                    <div class="stats shadow">
                        <div class="stat py-2 px-4">
                            <div class="stat-title">Ready</div>
                            <div class="stat-value text-2xl">{{ $this->readinessScore() }}%</div>
                            <div class="stat-desc">
                                @if ($this->isCriticalComplete())
                                    Critical setup complete
                                @else
                                    Critical setup incomplete
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <progress
                    class="progress progress-primary w-full"
                    value="{{ $this->readinessScore() }}"
                    max="100"
                ></progress>
            </div>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Checklist</h2>
                <ul class="menu bg-base-200 rounded-box w-full">
                    @foreach ($this->checklistItems() as $item)
                        <li>
                            <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-start gap-3">
                                    @if ($item['done'])
                                        <span class="badge badge-success badge-outline mt-0.5">Done</span>
                                    @else
                                        <span class="badge badge-warning badge-outline mt-0.5">Pending</span>
                                    @endif
                                    <div>
                                        <div class="font-medium">{{ $item['label'] }}</div>
                                        @if (filled($item['description'] ?? null))
                                            <div class="text-sm opacity-70 font-normal">
                                                {{ $item['description'] }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                @if (filled($item['href'] ?? null))
                                    <a href="{{ $item['href'] }}" class="btn {{ $item['done'] ? 'btn-ghost' : 'btn-primary' }} btn-sm shrink-0">
                                        {{ $item['done'] ? 'Review' : 'Complete' }}
                                    </a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-filament-panels::page>
