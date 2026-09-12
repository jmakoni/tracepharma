<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="alert alert-info">
            <span>
                Closure packaging over existing tracing broadcasts and site recall reconciliation —
                not a separate Recall+ engine. Use Site recall to quarantine or mark accounted.
            </span>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Open recalls</h2>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Recall</th>
                                <th>Status</th>
                                <th>Partner ack</th>
                                <th class="text-right">Unreconciled on-hand</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows() as $row)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $row['title'] }}</div>
                                        <div class="text-xs opacity-60">#{{ $row['id'] }}</div>
                                    </td>
                                    <td>{{ $row['status'] }}</td>
                                    <td>
                                        @if ($row['ack_percent'] !== null)
                                            <span class="badge {{ $row['ack_percent'] >= 100 ? 'badge-success' : 'badge-warning' }} badge-sm">
                                                {{ $row['ack_percent'] }}%
                                            </span>
                                        @endif
                                        <span class="text-sm opacity-70">{{ $row['ack_label'] }}</span>
                                    </td>
                                    <td class="text-right font-mono">
                                        {{ $row['unreconciled'] }}
                                        @if (! empty($row['unreconciled_truncated']))
                                            <div class="text-xs opacity-60">cap — may undercount</div>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <div class="flex flex-wrap gap-2 justify-end">
                                            @if (filled($row['href']))
                                                <a href="{{ $row['href'] }}" class="btn btn-sm btn-ghost">View</a>
                                            @endif
                                            @if (filled($row['site_recall_href']))
                                                <a href="{{ $row['site_recall_href'] }}" class="btn btn-sm btn-primary">Site recall</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-sm opacity-70">No open or in-progress recalls.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
