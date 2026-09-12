<x-filament-panels::page>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4 overflow-x-auto">
            <p class="text-sm opacity-70">
                Counts of EPCIS ingest exceptions on inbound documents. Internal operational signal only —
                not clean-data certified / not TraceReady.
            </p>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Partner</th>
                        <th class="text-right">Exceptions (7d)</th>
                        <th class="text-right">Exceptions (30d)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->partnerRows() as $row)
                        <tr>
                            <td>{{ $row['partner_name'] }}</td>
                            <td class="text-right tabular-nums">{{ $row['exceptions_7d'] }}</td>
                            <td class="text-right tabular-nums">{{ $row['exceptions_30d'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="opacity-70">
                                No inbound ingest exceptions in the last 30 days.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
