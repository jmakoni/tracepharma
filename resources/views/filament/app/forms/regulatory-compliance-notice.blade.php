{{-- Prominent compliance banner for App-panel mutating action modals --}}
<div
    class="tp-regulatory-notice alert alert-warning"
    role="status"
    aria-live="polite"
>
    <x-filament::icon
        icon="heroicon-o-shield-exclamation"
        class="tp-regulatory-notice__icon size-6 shrink-0"
    />
    <div class="tp-regulatory-notice__body min-w-0">
        <p class="tp-regulatory-notice__label">
            Regulatory notice
        </p>
        <p class="tp-regulatory-notice__text">
            {{ $notice }}
        </p>
    </div>
</div>
