<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert">
            <span>
                Stage and prod hub edges share this Admin. Partners POST to the environment host with
                <code>X-Epcis-Hub-Token</code> or <code>X-Inbound-Token</code>; tenants must match that environment and be granted hub providers.
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Platform hub settings</h2>
                {{ $this->content }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
