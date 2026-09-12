<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert alert-info">
            <span>
                Use this checklist when an inspector arrives. Download the site Inspection pack ZIP,
                walk ATP readiness, confirm open exceptions/quarantine, and keep SOP + Alert Center handy.
                Retention remains six years for EPCIS and compliance evidence.
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="card-title text-base">Walk-in readiness</h2>
                        <p class="text-sm opacity-70">Links existing TracePharma evidence — no second export engine.</p>
                    </div>
                    <div class="stats shadow">
                        <div class="stat py-2 px-4">
                            <div class="stat-title">Ready</div>
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
