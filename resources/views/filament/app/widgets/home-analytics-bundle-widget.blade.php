<x-filament-widgets::widget>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="card-title text-base">Analytics</h2>
                    <p class="text-sm opacity-70">As of {{ $asOf }} · last 7 days</p>
                </div>
                @if ($analyticsUrl)
                    <a href="{{ $analyticsUrl }}" class="btn btn-ghost btn-sm">
                        Open Analytics
                    </a>
                @endif
            </div>

            @forelse ($widgets as $widget)
                <section class="flex flex-col gap-3" wire:key="home-analytics-{{ $widget['key'] }}">
                    <div>
                        <h3 class="font-semibold">{{ $widget['label'] }}</h3>
                        <p class="text-sm opacity-70">{{ $widget['description'] }}</p>
                    </div>

                    @php
                        $partialHtml = null;

                        if ($widget['compatible']) {
                            try {
                                $partialHtml = $__env->make(
                                    $widget['view'],
                                    array_merge(
                                        \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']),
                                        ['data' => $widget['data']],
                                    ),
                                )->render();
                            } catch (Throwable) {
                                $partialHtml = null;
                            }
                        }
                    @endphp

                    @if (filled($partialHtml))
                        {!! $partialHtml !!}
                    @else
                        <p class="text-sm opacity-70">
                            This chart isn’t available on Home. Open Analytics for the full view.
                        </p>
                    @endif
                </section>
            @empty
                <p class="text-sm opacity-70">No analytics widgets are enabled on Home.</p>
            @endforelse
        </div>
    </div>
</x-filament-widgets::widget>
