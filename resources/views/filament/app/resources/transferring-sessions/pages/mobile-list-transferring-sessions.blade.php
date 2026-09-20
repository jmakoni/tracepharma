<x-filament-panels::page>
    @include('filament.app.partials.transfer-layout-switch', [
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
            @if ($this->showsCreateTransfer())
                <a href="{{ $this->createUrl() }}" class="btn btn-sm btn-primary" wire:navigate>New transfer</a>
            @endif
        </header>

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
                            · {{ $session->fromSite?->name ?? 'From' }}
                            → {{ $session->toSite?->name ?? 'To' }}
                        </span>
                        <span class="tp-floor-list__row-meta">
                            {{ str_replace('_', ' ', (string) $session->status) }}
                        </span>
                    </a>
                </li>
            @empty
                <li class="tp-floor-list__empty">{{ $this->emptyListMessage() }}</li>
            @endforelse
        </ul>

        @include('filament.app.partials.floor-bottom-nav', ['active' => 'none'])
    </div>
</x-filament-panels::page>
