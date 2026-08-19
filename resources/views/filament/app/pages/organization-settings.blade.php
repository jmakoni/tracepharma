<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert">
            <span>
                Company identity used for receiving site resolution, ATP evaluation, and GLN exports.
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Organization settings</h2>
                {{ $this->content }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
