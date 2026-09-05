<x-filament-panels::page>
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body gap-4 overflow-x-auto">
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
                    @forelse ($this->partnerAtpRows() as $row)
                        <tr>
                            <td>{{ $row['partner'] }}</td>
                            <td>{{ $row['site'] }}</td>
                            <td><span class="badge badge-outline">{{ $row['status'] }}</span></td>
                            <td>{{ $row['detail'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="opacity-70">No partner sites on record yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
