@php
    $options = \App\Support\Auth\CurrentSite::options();
    $siteId = \App\Support\Auth\CurrentSite::id();
    $currentLabel = $siteId !== null
        ? ($options[$siteId] ?? $options[(string) $siteId] ?? \App\Models\Site::query()->find($siteId)?->name)
        : 'Site';
@endphp

<div
    wire:ignore
    x-data="{ open: false }"
    x-on:tp-floor-site-open.window="open = true"
    @keydown.escape.window="open = false"
>
    <div
        x-show="open"
        x-cloak
        class="tp-floor-receive__sheet-backdrop"
        @click="open = false"
        aria-hidden="true"
    ></div>
    <div
        x-show="open"
        x-cloak
        x-transition:enter="tp-floor-receive__sheet-enter"
        x-transition:enter-start="tp-floor-receive__sheet-enter-start"
        x-transition:enter-end="tp-floor-receive__sheet-enter-end"
        x-transition:leave="tp-floor-receive__sheet-leave"
        x-transition:leave-start="tp-floor-receive__sheet-leave-start"
        x-transition:leave-end="tp-floor-receive__sheet-leave-end"
        class="tp-floor-receive__sheet"
        role="dialog"
        aria-modal="true"
        aria-label="Choose site"
    >
        <div class="tp-floor-receive__sheet-header">
            <h2 class="tp-floor-receive__sheet-title">Site</h2>
            <button type="button" class="tp-floor-receive__sheet-close" @click="open = false">Close</button>
        </div>
        <p class="text-sm opacity-70 mb-3">Current: {{ $currentLabel }}</p>
        <ul class="tp-floor-site-list">
            @forelse ($options as $id => $label)
                @php $isCurrent = (int) $id === (int) $siteId; @endphp
                <li>
                    <form method="POST" action="{{ route('tenant.current-site.set', ['site' => (int) $id]) }}">
                        @csrf
                        <button
                            type="submit"
                            class="tp-floor-site-list__btn {{ $isCurrent ? 'tp-floor-site-list__btn--current' : '' }}"
                        >
                            {{ $label }}
                        </button>
                    </form>
                </li>
            @empty
                <li class="text-sm opacity-70">No sites available.</li>
            @endforelse
        </ul>
    </div>
</div>
