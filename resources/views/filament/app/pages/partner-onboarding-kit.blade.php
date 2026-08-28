<x-filament-panels::page>
    <div
        class="flex flex-col gap-4"
        x-data="{
            copyText(text) {
                if (navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(text);
                }
            }
        }"
        x-on:copy-it-brief.window="copyText($event.detail.text)"
    >
        <div class="alert alert-info">
            <span>
                Use this kit when onboarding a new supplier or dispenser connection.
                For organization GLN and site defaults, see
                <a href="{{ \App\Filament\App\Pages\OnboardingWizard::getUrl(panel: 'app') }}" class="link">Getting started</a>.
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="card-title text-base">Progress</h2>
                        <p class="text-sm opacity-70">Required steps exclude the optional customer portal.</p>
                    </div>
                    <div class="stats shadow">
                        <div class="stat py-2 px-4">
                            <div class="stat-title">Complete</div>
                            <div class="stat-value text-2xl">{{ $this->kitScore() }}%</div>
                        </div>
                    </div>
                </div>
                <progress class="progress progress-primary w-full" value="{{ $this->kitScore() }}" max="100"></progress>
            </div>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Steps</h2>
                <ol class="list-decimal list-inside flex flex-col gap-4">
                    @foreach ($this->kitSteps() as $step)
                        <li class="border border-base-300 rounded-box p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="flex items-center gap-2 font-medium">
                                        @if ($step['done'])
                                            <span class="badge badge-success badge-sm">Done</span>
                                        @else
                                            <span class="badge badge-warning badge-sm">Pending</span>
                                        @endif
                                        {{ $step['title'] }}
                                    </div>
                                    <p class="text-sm opacity-70 mt-1 ml-0">{{ $step['description'] }}</p>
                                </div>
                                @if (filled($step['href'] ?? null))
                                    <a
                                        href="{{ $step['href'] }}"
                                        class="btn btn-sm {{ $step['done'] ? 'btn-ghost' : 'btn-primary' }} shrink-0"
                                    >
                                        {{ $step['action_label'] ?? 'Open' }}
                                    </a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </div>
</x-filament-panels::page>
