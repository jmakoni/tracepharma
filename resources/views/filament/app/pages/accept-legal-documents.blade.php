<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <x-legal.legal-summary
            :show-user-acceptance="true"
            :user="auth()->user()"
        />

        @if ($this->needsAcceptance())
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <h2 class="card-title text-base">Record your acceptance</h2>
                    <p class="text-sm opacity-80">
                        Check both boxes after you have read the current documents. Logout remains available from the account menu.
                    </p>
                    {{ $this->content }}
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
