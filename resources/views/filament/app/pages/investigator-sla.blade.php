<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Receive-blocking exceptions</h2>
                <p class="text-sm opacity-70">
                    DSCSA 72-hour supplier correction for HardBlocking / BusinessRule cases that are still true. Waiting on partner pauses the clock. Cleared and resolved cases leave this board.
                </p>

                @forelse ($this->blockingCases() as $case)
                    <div class="rounded-lg border border-base-300 px-3 py-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <a href="{{ $this->exceptionUrl($case) }}" class="link link-hover font-semibold">
                                    {{ $case->caseReference() }}
                                </a>
                                <span class="badge badge-outline">{{ $case->type?->code ?? '—' }}</span>
                                @if ($this->clockBreached($case))
                                    <span class="badge badge-error">{{ $this->clockLabel($case) }}</span>
                                @else
                                    <span class="badge badge-warning">{{ $this->clockLabel($case) }}</span>
                                @endif
                            </div>
                            <p class="text-sm">{{ $case->title }}</p>
                            <p class="text-sm opacity-70">
                                {{ $case->tradingPartner?->name ?? 'No partner' }}
                                · {{ $this->lastEmailLabel($case) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="btn btn-ghost min-h-12"
                                wire:click="mountAction('recheckCondition', { case: {{ (int) $case->getKey() }} })"
                            >
                                Re-check
                            </button>
                            @if ($case->document_id)
                                <button
                                    type="button"
                                    class="btn btn-ghost min-h-12"
                                    wire:click="mountAction('reevaluateFindingsCase', { case: {{ (int) $case->getKey() }} })"
                                >
                                    Re-evaluate findings
                                </button>
                            @endif
                            <button
                                type="button"
                                class="btn btn-primary min-h-12"
                                wire:click="mountAction('emailSupplier', { case: {{ (int) $case->getKey() }} })"
                            >
                                Email supplier
                            </button>
                        </div>
                    </div>
                @empty
                    <p class="text-sm opacity-70">No open receive-blocking exceptions.</p>
                @endforelse
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
