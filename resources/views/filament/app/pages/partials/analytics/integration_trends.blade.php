@php
    /** @var array{days: list<array{date: string, inbound_ok: int, inbound_wip: int, inbound_voided: int, inbound_fail: int, outbound_ok: int, outbound_fail: int}>} $data */
@endphp

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Day</th>
                <th>Inbound OK</th>
                <th>Inbound WIP</th>
                <th>Inbound voided</th>
                <th>Inbound fail</th>
                <th>Outbound OK</th>
                <th>Outbound fail</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['days'] as $row)
                <tr>
                    <td class="whitespace-nowrap">{{ $row['date'] }}</td>
                    <td>{{ number_format($row['inbound_ok']) }}</td>
                    <td>{{ number_format($row['inbound_wip'] ?? 0) }}</td>
                    <td>{{ number_format($row['inbound_voided'] ?? 0) }}</td>
                    <td>
                        @if ($row['inbound_fail'] > 0)
                            <span class="badge badge-error badge-outline">{{ number_format($row['inbound_fail']) }}</span>
                        @else
                            0
                        @endif
                    </td>
                    <td>{{ number_format($row['outbound_ok']) }}</td>
                    <td>
                        @if ($row['outbound_fail'] > 0)
                            <span class="badge badge-error badge-outline">{{ number_format($row['outbound_fail']) }}</span>
                        @else
                            0
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="opacity-70">No integration documents in this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
