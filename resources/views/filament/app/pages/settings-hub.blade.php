<x-filament-panels::page>
    @php
        $criticalScore = $this->criticalScore();
        $recommendedScore = $this->readinessScore();
        $done = $this->readinessDoneCount();
        $total = $this->readinessTotalCount();
        $criticalComplete = $this->isReadinessComplete();
        $recommendedComplete = $this->isRecommendedComplete();
        $incomplete = $this->incompleteChecklistItems();
        $completed = $this->completedChecklistItems();
        $sections = $this->cardSections();
        $opsHubUrl = $this->operationsHubUrl();
    @endphp

    <div data-tp-settings-hub class="flex flex-col gap-4">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="card-title text-base">Go-live readiness</h2>
                        <p class="text-sm opacity-70">
                            Critical path (company GLN and default sites) versus recommended setup.
                        </p>
                    </div>
                    <div class="stats shadow" aria-live="polite">
                        <div class="stat py-2 px-4">
                            <div class="stat-title">Critical</div>
                            <div class="stat-value text-2xl">{{ $criticalScore }}%</div>
                            <div class="stat-desc">
                                {{ $criticalComplete ? 'Go-live ready' : 'Required for go-live' }}
                            </div>
                        </div>
                        <div class="stat py-2 px-4">
                            <div class="stat-title">Recommended</div>
                            <div class="stat-value text-2xl">{{ $recommendedScore }}%</div>
                            <div class="stat-desc">{{ $done }} of {{ $total }} checklist</div>
                        </div>
                    </div>
                </div>

                @if ($total > 0)
                    <div
                        data-tp-settings-progress
                        role="progressbar"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="{{ $criticalScore }}"
                        aria-label="Critical go-live readiness {{ $criticalScore }} percent"
                    >
                        <div data-tp-settings-progress-bar style="width: {{ max(0, min(100, $criticalScore)) }}%"></div>
                    </div>
                @endif

                @if ($criticalComplete)
                    <div class="alert alert-success">
                        <div>
                            <div class="font-medium">Critical go-live ready</div>
                            <div class="text-sm opacity-70">
                                Company GLN and default site GLNs are set. You can receive product.
                            </div>
                            @if (filled($opsHubUrl))
                                <a href="{{ $opsHubUrl }}" class="btn btn-primary btn-sm mt-2">
                                    Open Operations Hub
                                </a>
                            @endif
                        </div>
                    </div>

                    @if (! $recommendedComplete)
                        <div class="alert alert-info">
                            <div>
                                <div class="font-medium">Recommended setup still open</div>
                                <div class="text-sm opacity-70">
                                    Partners, inbound path, and other checklist items improve day-two operations — finish when ready.
                                </div>
                            </div>
                        </div>
                    @endif
                @endif

                @if ($incomplete !== [])
                    <ul class="menu bg-base-200 rounded-box w-full" role="list" data-tp-settings-checklist>
                        @foreach ($incomplete as $item)
                            <li>
                                <div data-tp-settings-checklist-row>
                                    <div data-tp-settings-checklist-label>
                                        <span class="badge badge-warning badge-outline">Pending</span>
                                        <div>
                                            <div class="font-medium">{{ $item['label'] }}</div>
                                            @if (filled($item['description'] ?? null))
                                                <div class="text-sm opacity-70 font-normal">
                                                    {{ $item['description'] }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if (filled($item['href'] ?? null))
                                            <a href="{{ $item['href'] }}" class="btn btn-primary btn-sm">
                                                Complete
                                            </a>
                                        @elseif ($this->canDeferOutbound($item))
                                            <button
                                                type="button"
                                                wire:click="acknowledgeOutboundDeferred"
                                                class="btn btn-ghost btn-sm"
                                            >
                                                Defer outbound for now
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($completed !== [])
                    <div>
                        <button
                            type="button"
                            wire:click="toggleCompletedChecklist"
                            class="btn btn-ghost btn-sm"
                            aria-expanded="{{ $this->showCompletedChecklist ? 'true' : 'false' }}"
                        >
                            {{ $this->showCompletedChecklist
                                ? 'Hide completed'
                                : 'Show completed ('.count($completed).')' }}
                        </button>

                        @if ($this->showCompletedChecklist)
                            <ul class="menu bg-base-200 rounded-box mt-2 w-full" role="list" data-tp-settings-checklist>
                                @foreach ($completed as $item)
                                    <li>
                                        <div data-tp-settings-checklist-row>
                                            <div data-tp-settings-checklist-label>
                                                <span class="badge badge-success badge-outline">Done</span>
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
                                                <a href="{{ $item['href'] }}" class="btn btn-ghost btn-sm">
                                                    Open
                                                </a>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div>
                    <h2 class="card-title text-base">Configure</h2>
                    <p class="text-sm opacity-70">
                        Master data, integrations, and users for this organization.
                    </p>
                </div>

                @foreach ($sections as $section)
                    <div>
                        <h3 class="mb-2 text-sm font-semibold opacity-70">
                            {{ $section['title'] }}
                        </h3>
                        <div data-tp-settings-card-grid>
                            @foreach ($section['cards'] as $card)
                                <a
                                    href="{{ $card['url'] }}"
                                    data-tp-settings-card
                                    class="rounded-box border border-base-300 bg-base-200/60 p-4 transition hover:border-primary/40 hover:bg-base-200"
                                >
                                    <div class="flex items-start gap-3">
                                        <x-filament::icon
                                            :icon="$card['icon']"
                                            class="h-5 w-5 shrink-0 opacity-70"
                                        />
                                        <div>
                                            <div class="font-medium">{{ $card['label'] }}</div>
                                            <div class="text-sm opacity-70 font-normal">
                                                {{ $card['description'] }}
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-panels::page>
