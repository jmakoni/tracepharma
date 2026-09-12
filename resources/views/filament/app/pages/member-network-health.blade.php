<x-filament-panels::page>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4 overflow-x-auto">
            <p class="text-sm opacity-70">
                Sorted by at-risk signals. Soft-only roster members need a TracePharma tenant link for live metrics.
            </p>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>ATP gaps</th>
                        <th>Open exceptions</th>
                        <th>Aging 7d+</th>
                        <th>Connection</th>
                        <th>Last EPCIS success</th>
                        <th>Health</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->healthRows() as $row)
                        <tr @class(['opacity-70' => ! ($row['live_metrics'] ?? false)])>
                            <td class="font-medium">{{ $row['member_name'] }}</td>
                            <td>{{ $row['atp_gap_count'] }}</td>
                            <td>{{ $row['exceptions_open'] }}</td>
                            <td>{{ $row['exceptions_aging_7d'] }}</td>
                            <td>
                                @if (($row['connection_unhealthy'] ?? null) === 'N/A')
                                    N/A
                                @elseif ($row['connection_unhealthy'])
                                    <span class="badge badge-error badge-sm">Unhealthy</span>
                                @else
                                    <span class="badge badge-success badge-sm">OK</span>
                                @endif
                            </td>
                            <td class="font-mono text-xs">{{ $row['last_epcis_success_at'] ?? '—' }}</td>
                            <td>{{ $row['health_score'] ?? '—' }}</td>
                            <td class="text-sm">{{ $row['empty_hint'] ?? '' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="opacity-70">No members on the roster yet. Add pharmacies under Member roster, then run the rollup.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
