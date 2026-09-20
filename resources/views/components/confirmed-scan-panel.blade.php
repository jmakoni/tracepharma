@props([
    'rows' => [],
    'caption' => null,
    'heading' => 'Just scanned',
    'empty' => 'Scanned items will appear here',
    'removeConfirm' => 'Remove this scan from the receive session?',
    'removeMethod' => 'removeRecentScanLine',
])

@php
    /** @var list<array{id: int, label: string, type: string, can_remove: bool}> $rows */
    $rows = array_values(is_array($rows) ? $rows : []);
    $count = count($rows);
@endphp

<div class="tp-staged-scan-panel">
    <div class="tp-staged-scan-panel__header">
        <h3 class="tp-staged-scan-panel__heading">
            {{ $heading }}
            <span class="tp-staged-scan-panel__count badge badge-neutral">{{ $count }}</span>
        </h3>
    </div>

    @if (filled($caption))
        <p class="tp-floor-receive__recent-caption">{{ $caption }}</p>
    @endif

    @if ($count > 0)
        <ul class="tp-staged-scan-panel__list" aria-label="{{ $heading }}">
            @foreach ($rows as $row)
                <li class="tp-staged-scan-panel__row">
                    <div class="tp-staged-scan-panel__main">
                        @if (($row['type'] ?? '') !== '')
                            <span class="tp-staged-scan-panel__type badge badge-ghost badge-sm">{{ $row['type'] }}</span>
                        @endif
                        <span class="tp-staged-scan-panel__barcode font-mono">{{ $row['label'] }}</span>
                    </div>
                    @if ($row['can_remove'] ?? false)
                        <button
                            type="button"
                            class="tp-staged-scan-panel__remove btn btn-ghost btn-sm"
                            wire:click="{{ $removeMethod }}({{ (int) $row['id'] }})"
                            wire:confirm="{{ $removeConfirm }}"
                            aria-label="Remove"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="tp-staged-scan-panel__empty">{{ $empty }}</p>
    @endif
</div>
