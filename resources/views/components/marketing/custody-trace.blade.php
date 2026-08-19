<div {{ $attributes->class(['tp-custody-trace']) }}>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-tp-border pb-4">
        <p class="font-mono text-[11px] font-medium uppercase tracking-[0.2em] text-tp-teal-400">Chain of custody</p>
        <span class="tp-status tp-status--success">Ready to receive</span>
    </div>

    <div class="mt-5 space-y-4 lg:space-y-0">
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.identifier-chip label="SSCC" value="061414100000001233" />
            <span class="font-mono text-sm text-tp-accent-500/50" aria-hidden="true">→</span>
            <x-ui.identifier-chip label="STEP" value="shipping" />
        </div>

        <div class="hidden items-center gap-3 lg:flex" aria-hidden="true">
            <div class="h-px flex-1 bg-gradient-to-r from-tp-accent-500/40 via-tp-accent-500/15 to-transparent"></div>
            <span class="font-mono text-[10px] uppercase tracking-widest text-tp-muted">serialized product</span>
            <div class="h-px flex-1 bg-gradient-to-l from-tp-accent-500/40 via-tp-accent-500/15 to-transparent"></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.identifier-chip label="GTIN" value="06141411234562" />
            <span class="font-mono text-sm text-tp-accent-500/50" aria-hidden="true">→</span>
            <x-ui.identifier-chip label="SERIAL" value="123456789012" />
        </div>
    </div>

    <p class="mt-5 border-t border-tp-border pt-4 font-mono text-xs leading-relaxed text-tp-muted">
        <span class="text-tp-teal-400/90">scan</span>
        <span aria-hidden="true"> → </span>
        <span class="text-tp-teal-400/90">match epcis</span>
        <span aria-hidden="true"> → </span>
        <span class="text-tp-teal-400/90">confirm receipt</span>
        <span class="text-tp-muted"> — exceptions surface before inventory</span>
    </p>
</div>