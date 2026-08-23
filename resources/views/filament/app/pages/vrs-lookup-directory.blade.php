<x-filament-panels::page>
    <div class="card bg-base-100 shadow-xl max-w-2xl">
        <div class="card-body gap-4">
            <div>
                <div class="text-sm opacity-70">Publish (partners verify you)</div>
                <div class="font-mono text-sm break-all">{{ $this->publishUrl() ?? '—' }}</div>
                <p class="text-sm opacity-70 mt-1">
                    Route <span class="font-mono">webhooks.vrs.responder</span>.
                    Responder key {{ $this->responderConfigured() ? 'is set' : 'is not set' }}.
                </p>
            </div>
            <div>
                <div class="text-sm opacity-70">Consume (you verify a product)</div>
                <div class="font-mono text-sm">{{ $this->consumePath() }}</div>
                <p class="text-sm opacity-70 mt-1">Same gate as Verify Product. Ability <span class="font-mono">vrs:dispense-check</span>.</p>
            </div>
            <div>
                <div class="text-sm opacity-70">Requestor GLN</div>
                <div class="font-mono text-sm">{{ $this->requestorGln() ?: 'Not set on the organization' }}</div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
