<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert alert-info">
            <span>
                Download the full SOP starter pack as a printable PDF from the page header.
            </span>
        </div>

        @foreach ($this->sops() as $sop)
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-3">
                    <h2 class="card-title text-base">{{ $sop['title'] }}</h2>
                    <p class="text-sm opacity-70">{{ $sop['summary'] }}</p>
                    <ol class="list-decimal pl-5 text-sm flex flex-col gap-1">
                        @foreach ($sop['steps'] as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
