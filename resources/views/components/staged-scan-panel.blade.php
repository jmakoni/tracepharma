@props([
    'stagedScans' => [],
])

@php
    /** @var list<string> $stagedScans */
    $stagedScans = array_values($stagedScans);
    $stagedCount = count($stagedScans);
@endphp

<div class="tp-staged-scan-panel">
    <div class="tp-staged-scan-panel__header">
        <h3 class="tp-staged-scan-panel__heading">
            Staged scans
            <span class="tp-staged-scan-panel__count badge badge-neutral">{{ $stagedCount }}</span>
        </h3>
        @if ($stagedCount > 0)
            <button
                type="button"
                class="tp-staged-scan-panel__clear btn btn-ghost btn-sm"
                wire:click="clearStagedScans"
            >
                Clear all
            </button>
        @endif
    </div>

    @if ($stagedCount > 0)
        <ul class="tp-staged-scan-panel__list" aria-label="Staged scans">
            @foreach ($stagedScans as $i => $barcode)
                <li class="tp-staged-scan-panel__row">
                    <span class="tp-staged-scan-panel__barcode font-mono">{{ $barcode }}</span>
                    <button
                        type="button"
                        class="tp-staged-scan-panel__remove btn btn-ghost btn-sm"
                        wire:click="removeStagedScan({{ $i }})"
                    >
                        Remove
                    </button>
                </li>
            @endforeach
        </ul>
    @else
        <p class="tp-staged-scan-panel__empty">Scan barcodes to stage, then confirm.</p>
    @endif

    <button
        type="button"
        class="tp-staged-scan-panel__confirm tp-scanner-macro-btn tp-floor-receive__complete-btn--ready"
        wire:click="confirmStagedScans"
        wire:loading.attr="disabled"
        @disabled($stagedCount === 0)
    >
        Confirm staged
    </button>
</div>
