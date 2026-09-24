<x-filament-panels::page>
    @include('filament.app.partials.receive-layout-switch', [
        'mode' => 'floor',
        'desktopUrl' => $this->desktopListUrl(),
        'floorUrl' => $this->floorListUrl(),
    ])

    <div class="tp-floor-receive tp-floor-list">
        <header class="tp-floor-receive__sticky-header">
            <div class="tp-floor-receive__header-top">
                @include('filament.app.partials.floor-task-menu')
                <h1 class="text-lg font-semibold">{{ $this->listHeading() }}</h1>
            </div>
            @if ($this->listMode() === 'scan-first' && \App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource::canCreate())
                <button
                    type="button"
                    class="btn btn-sm btn-primary min-h-12"
                    wire:click="startScanFirst"
                    wire:loading.attr="disabled"
                >
                    New scan-first
                </button>
            @endif
        </header>

        @if ($this->listMode() === 'scan-first')
            <ul class="tp-floor-list__rows" role="list">
                @forelse ($this->sessions() as $session)
                    <li>
                        <a
                            href="{{ $this->floorSessionUrl($session) }}"
                            class="tp-floor-list__row"
                            wire:navigate
                        >
                            <span class="tp-floor-list__row-title">
                                #{{ $session->getKey() }}
                                · {{ $session->site?->name ?? 'Site' }}
                            </span>
                            <span class="tp-floor-list__row-meta">
                                {{ str_replace('_', ' ', (string) $session->status) }}
                                · scan first
                            </span>
                        </a>
                    </li>
                @empty
                    <li class="tp-floor-list__empty">
                        No open scan-first sessions.
                        @if (\App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource::canCreate())
                            Tap <strong>New scan-first</strong> to begin.
                        @endif
                    </li>
                @endforelse
            </ul>
        @else
            <ul class="tp-floor-list__rows" role="list">
                @forelse ($this->documents() as $document)
                    <li>
                        <button
                            type="button"
                            class="tp-floor-list__row w-full text-left"
                            wire:click="openDocument({{ (int) $document->getKey() }})"
                            wire:loading.attr="disabled"
                        >
                            <span class="tp-floor-list__row-title">{{ $this->documentRowTitle($document) }}</span>
                            <span class="tp-floor-list__row-meta">{{ $this->documentRowMeta($document) }}</span>
                        </button>
                    </li>
                @empty
                    <li class="tp-floor-list__empty">
                        No inbound files ready to receive.
                    </li>
                @endforelse
            </ul>
        @endif

        @include('filament.app.partials.floor-bottom-nav', [
            'active' => $this->listMode() === 'scan-first' ? 'none' : 'receipts',
        ])
    </div>
</x-filament-panels::page>
