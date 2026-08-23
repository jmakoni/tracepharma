<x-filament-panels::page>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Receive fill (30d)</th>
                            <th>Open exceptions</th>
                            <th>Aging 7d+</th>
                            <th>VRS fail (30d)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->rows() as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td>
                                    @if ($row['receive_pct'] === null)
                                        —
                                    @else
                                        {{ $row['receive_pct'] }}%
                                        <span class="opacity-70 text-xs">
                                            ({{ $row['receive_confirmed'] }}/{{ $row['receive_expected'] }})
                                        </span>
                                    @endif
                                </td>
                                <td>{{ $row['exceptions_open'] }}</td>
                                <td>{{ $row['aging_7d_plus'] }}</td>
                                <td>
                                    @if ($row['vrs_fail_pct'] === null)
                                        —
                                    @else
                                        {{ $row['vrs_fail_pct'] }}%
                                        <span class="opacity-70 text-xs">
                                            ({{ $row['vrs_blocked'] }}/{{ $row['vrs_total'] }})
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-sm opacity-70">No organization sites to roll up.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
