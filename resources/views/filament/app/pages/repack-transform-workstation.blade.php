<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert alert-info">
            <span>
                Pack and Break &amp; pack remain aggregation tools. This page authors a
                <strong>TransformationEvent</strong> for repack lineage only — original-link TI is deferred.
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                {{ $this->content }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
