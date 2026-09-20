@php
    $tiles = \App\Support\Floor\FloorTaskMenu::tiles();
@endphp

@if ($tiles !== [])
    <div
        class="tp-floor-task-menu"
        wire:ignore
        x-data="{ open: false }"
        @keydown.escape.window="open = false"
    >
        <button
            type="button"
            class="tp-floor-task-menu__btn"
            @click="open = !open"
            :aria-expanded="open"
            aria-controls="tp-floor-task-menu-panel"
            aria-label="Floor tasks"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="tp-floor-task-menu__icon" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>

        <div
            x-show="open"
            x-cloak
            class="tp-floor-task-menu__backdrop"
            @click="open = false"
            aria-hidden="true"
        ></div>

        <nav
            id="tp-floor-task-menu-panel"
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-1"
            class="tp-floor-task-menu__panel"
            aria-label="Floor tasks"
        >
            <ul class="tp-floor-task-menu__list">
                @foreach ($tiles as $tile)
                    <li>
                        <a
                            href="{{ $tile['url'] }}"
                            class="tp-floor-task-menu__tile"
                            wire:navigate
                        >{{ $tile['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>
@endif
