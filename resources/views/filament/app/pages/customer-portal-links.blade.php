<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @if ($this->supportsClientPortalV2())
            <div class="alert alert-success">
                <span>
                    OTP client portal is enabled for this tenant. Use
                    <strong>Invite to client portal</strong> for email login membership.
                    Legacy signed links below remain available as a fallback.
                </span>
            </div>
        @endif

        @if ($this->issuedUrl)
            <div class="alert alert-info">
                <div class="flex flex-col gap-1">
                    <span class="font-semibold">Signed customer portal URL</span>
                    <span class="font-mono text-sm break-all">{{ $this->issuedUrl }}</span>
                </div>
            </div>
        @endif

        @if ($this->supportsClientPortalV2())
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <h2 class="card-title text-base">Client portal users</h2>
                    @forelse ($this->portalUsers() as $portalUser)
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-base-300 px-3 py-3">
                            <div>
                                <div class="font-semibold font-mono text-sm">{{ $portalUser->email }}</div>
                                <div class="text-sm opacity-70">
                                    @if (! $portalUser->is_active)
                                        Disabled
                                    @elseif ($portalUser->isLocked())
                                        Locked until {{ $portalUser->locked_until?->toDateTimeString() }}
                                    @else
                                        Active
                                    @endif
                                    · {{ $portalUser->organizations->pluck('name')->join(', ') ?: 'No org' }}
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($portalUser->isLocked())
                                    <button
                                        type="button"
                                        class="btn btn-warning min-h-12"
                                        wire:click="unlockPortalUser({{ (int) $portalUser->getKey() }})"
                                    >
                                        Unlock
                                    </button>
                                @endif
                                <button
                                    type="button"
                                    class="btn {{ $portalUser->is_active ? 'btn-error' : 'btn-success' }} min-h-12"
                                    wire:click="togglePortalUserActive({{ (int) $portalUser->getKey() }})"
                                    wire:confirm="{{ $portalUser->is_active ? 'Disable this portal user?' : 'Enable this portal user?' }}"
                                >
                                    {{ $portalUser->is_active ? 'Disable' : 'Enable' }}
                                </button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm opacity-70">No portal users yet. Invite one above.</p>
                    @endforelse
                </div>
            </div>
        @endif

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Active trading partners</h2>
                @forelse ($this->partners() as $partner)
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-base-300 px-3 py-3">
                        <div>
                            <div class="font-semibold">{{ $partner->name }}</div>
                            <div class="text-sm opacity-70 font-mono">{{ $partner->gln }}</div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-primary min-h-12"
                            wire:click="mountAction('issueLink', { partner: {{ (int) $partner->getKey() }} })"
                        >
                            Issue link
                        </button>
                    </div>
                @empty
                    <p class="text-sm opacity-70">No active trading partners.</p>
                @endforelse
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
