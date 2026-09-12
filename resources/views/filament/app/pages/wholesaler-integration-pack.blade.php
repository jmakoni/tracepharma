<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert alert-info">
            <span>
                TracePharma ships a WMS ship-confirm bridge
                (<code class="font-mono text-xs">POST /api/webhooks/wms/{tenantId}</code> with
                <code class="font-mono text-xs">X-Wms-Api-Key</code>, plus Sanctum
                <code class="font-mono text-xs">POST /api/v1/wms/ship-confirm</code>).
                Outbound EPCIS uses <code class="font-mono text-xs">epcis:transmit</code>.
                Full guide: <code class="font-mono text-xs">docs/integrations/wms.md</code>.
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="card-title text-base">Certification progress</h2>
                        <p class="text-sm opacity-70">WMS bridge key or ship-confirm token unlock the pilot path.</p>
                    </div>
                    <div class="stats shadow">
                        <div class="stat py-2 px-4">
                            <div class="stat-title">Complete</div>
                            <div class="stat-value text-2xl">{{ $this->checklistScore() }}%</div>
                        </div>
                    </div>
                </div>
                <progress class="progress progress-primary w-full" value="{{ $this->checklistScore() }}" max="100"></progress>
            </div>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Checklist</h2>
                <ol class="list-decimal list-inside flex flex-col gap-4">
                    @foreach ($this->checklistItems() as $item)
                        <li class="border border-base-300 rounded-box p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="flex items-center gap-2 font-medium">
                                        @if ($item['done'])
                                            <span class="badge badge-success badge-sm">Done</span>
                                        @else
                                            <span class="badge badge-warning badge-sm">Pending</span>
                                        @endif
                                        {{ $item['title'] }}
                                    </div>
                                    <p class="text-sm opacity-70 mt-1">{{ $item['description'] }}</p>
                                </div>
                                @if (filled($item['href'] ?? null))
                                    <a
                                        href="{{ $item['href'] }}"
                                        class="btn btn-sm {{ $item['done'] ? 'btn-ghost' : 'btn-primary' }} shrink-0"
                                    >
                                        {{ $item['action_label'] ?? 'Open' }}
                                    </a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </div>
</x-filament-panels::page>
