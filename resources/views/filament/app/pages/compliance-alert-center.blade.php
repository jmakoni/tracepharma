<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @php($alerts = $this->alerts())

        @if ($alerts === [])
            <div class="alert alert-success">
                <span>No active alerts. Integration, exceptions, ATP, and inbound queue look healthy.</span>
            </div>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($alerts as $alert)
                    <div @class([
                        'alert',
                        'alert-error' => $alert['severity'] === 'critical',
                        'alert-warning' => $alert['severity'] === 'warning',
                    ])>
                        <div class="flex flex-1 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                            <div>
                                <div class="font-semibold">{{ $alert['title'] }}</div>
                                <div class="text-sm opacity-80">{{ $alert['detail'] }}</div>
                            </div>
                            @if (! empty($alert['href']))
                                <a href="{{ $alert['href'] }}" class="btn btn-sm btn-ghost shrink-0">
                                    Open
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if (\App\Filament\App\Pages\AtpPartnerReadiness::canAccess())
            @php($partnerRows = $this->partnerAtpRows())
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-3">
                    <h2 class="card-title text-base">Partner ATP snapshot</h2>
                    <p class="text-sm opacity-70">
                        Active partner sites (capped). Use ATP readiness for full remediation.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Partner</th>
                                    <th>Site</th>
                                    <th>Status</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($partnerRows as $row)
                                    <tr>
                                        <td>{{ $row['partner'] }}</td>
                                        <td>{{ $row['site'] }}</td>
                                        <td><span class="badge badge-ghost badge-sm">{{ $row['status'] }}</span></td>
                                        <td class="text-sm opacity-80">{{ $row['detail'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-sm opacity-70">No active partner sites.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <a href="{{ \App\Filament\App\Pages\AtpPartnerReadiness::getUrl(panel: 'app') }}" class="btn btn-sm btn-primary w-fit">
                        Open ATP readiness
                    </a>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
