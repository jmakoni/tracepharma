<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($this->reportCatalog() as $report)
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body gap-2">
                        <h2 class="card-title text-base">{{ $report['label'] }}</h2>
                        <p class="text-sm opacity-80">{{ $report['description'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                {{ $this->content }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
