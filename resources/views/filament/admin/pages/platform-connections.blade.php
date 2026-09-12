<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert">
            <span>
                Stage and prod edges share this Admin. Partners authenticate with
                <code>X-Epcis-Hub-Token</code> (HTTPS hub) or their registered AS2 certificate; tenants must match
                that environment and be granted hub providers. Secrets are write-only and encrypted at rest.
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">TracePharma-owned connection edges</h2>
                {{ $this->content }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
