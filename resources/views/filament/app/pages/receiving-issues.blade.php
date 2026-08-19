<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="form-control w-full max-w-xl gap-1.5">
                    <label for="receiving-issues-session" class="label-text text-sm font-medium">
                        Completed receiving session
                    </label>
                    <select
                        id="receiving-issues-session"
                        class="select select-bordered w-full"
                        wire:change="selectSession($event.target.value)"
                    >
                        <option value="">Select a completed session…</option>
                        @foreach ($this->completedSessionOptions() as $id => $label)
                            <option value="{{ $id }}" @selected((int) $this->sessionId === (int) $id)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if (! $this->session())
                    <div role="status" class="alert">
                        <div class="flex flex-col gap-1">
                            <span class="font-semibold">No shipment selected</span>
                            <span class="text-sm opacity-80">
                                Pick a completed receiving session, or open
                                <span class="font-medium">Report receiving issues</span>
                                from a finished receive.
                            </span>
                        </div>
                    </div>
                @else
                    @php($session = $this->session())
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="badge badge-success badge-outline">Completed</span>
                            <span>{{ $session->tradingPartner?->name ?? 'No partner on file' }}</span>
                            @if ($session->site?->name)
                                <span aria-hidden="true">·</span>
                                <span>{{ $session->site->name }}</span>
                            @endif
                        </div>
                        @if ($url = $this->sessionViewUrl())
                            <a href="{{ $url }}" class="btn btn-sm btn-ghost">
                                Back to receive session
                            </a>
                        @endif
                    </div>

                    <div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow" aria-live="polite">
                        <div class="stat">
                            <div class="stat-title">Pallets</div>
                            <div class="stat-value text-2xl">
                                {{ $session->confirmed_parent_count }}/{{ $session->expected_parent_count }}
                            </div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Units</div>
                            <div class="stat-value text-2xl">
                                {{ $session->confirmed_child_count }}/{{ $session->expected_child_count }}
                            </div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Unconfirmed expected</div>
                            <div class="stat-value text-2xl">{{ $this->shortageCount() }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Unexpected</div>
                            <div class="stat-value text-2xl">{{ $this->overageCount() }}</div>
                        </div>
                    </div>

                    <p class="text-sm opacity-70">
                        Use the header actions to file shortage, overage, or damaged claims.
                        These open Exception cases (and quarantine for damaged) — they are not on the scan HUD.
                    </p>
                @endif
            </div>
        </div>

        @if ($this->session() && ($cases = $this->openCasesForSession())->isNotEmpty())
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-3">
                    <h2 class="card-title text-base">Open exceptions for this shipment</h2>
                    <ul class="divide-y divide-base-200">
                        @foreach ($cases as $case)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <div class="flex flex-col gap-0.5">
                                    <span class="font-medium">{{ $case->title }}</span>
                                    <span class="opacity-70">
                                        {{ $case->type?->code ?? 'UNCLASSIFIED' }}
                                        · {{ $case->status?->label() ?? $case->status }}
                                    </span>
                                </div>
                                @if ($url = $this->exceptionUrl($case))
                                    <a href="{{ $url }}" class="btn btn-sm btn-outline">Open</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
