@props([
    'compact' => false,
])

<div {{ $attributes->class(['tp-card border-tp-border', $compact ? 'p-5' : 'p-6 sm:p-8']) }} aria-label="Serialization stack: L3, L4, and L5">
  <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">Where TracePharma fits</p>
  <p class="mt-2 text-sm leading-relaxed text-tp-muted">
    Your plant runs L3 line software. TracePharma is the <strong class="font-medium text-tp-ink">L4 corporate hub</strong>—EPCIS, partners, and compliance. National hubs (L5) stay outside our product; we connect your trading partners.
  </p>

  <ol class="mt-6 space-y-3">
    <li class="flex gap-4 rounded-lg border border-tp-border bg-tp-canvas px-4 py-3">
      <span class="font-mono text-xs font-medium text-tp-muted">L3</span>
      <div>
        <p class="text-sm font-medium text-tp-ink">Plant serialization</p>
        <p class="mt-0.5 text-xs leading-relaxed text-tp-muted">Line controllers, commissioning — software you already run</p>
      </div>
    </li>
    <li class="flex gap-4 rounded-lg border border-tp-accent-500/40 bg-tp-accent-500/10 px-4 py-3 ring-1 ring-tp-accent-500/20">
      <span class="font-mono text-xs font-medium text-tp-teal-400">L4</span>
      <div>
        <p class="text-sm font-semibold text-tp-ink">TracePharma</p>
        <p class="mt-0.5 text-xs leading-relaxed text-tp-muted">EPCIS repository, partner connectivity, exceptions, compliance workflows</p>
      </div>
    </li>
    <li class="flex gap-4 rounded-lg border border-tp-border bg-tp-canvas px-4 py-3 opacity-80">
      <span class="font-mono text-xs font-medium text-tp-muted">L5</span>
      <div>
        <p class="text-sm font-medium text-tp-muted">National / regulatory hubs</p>
        <p class="mt-0.5 text-xs leading-relaxed text-tp-muted">FDA, EMVO, country hubs — not TracePharma; partners may connect upstream</p>
      </div>
    </li>
  </ol>
</div>
