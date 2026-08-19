<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert">
            <span>
                Home is a decision surface — queues, pulse, and actions. Trends and comparisons live on Analytics.
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Dashboard widgets</h2>
                {{ $this->content }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
