@php
    /** @var array{days: list<array{date: string, receive: int, ship: int}>, receive_total: int, ship_total: int} $data */
    $max = max([1, ...array_map(fn (array $row): int => $row['receive'] + $row['ship'], $data['days'])]);
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">Received</div>
        <div class="stat-value text-2xl">{{ number_format($data['receive_total']) }}</div>
        <div class="stat-desc">Completed receive sessions</div>
    </div>
    <div class="stat">
        <div class="stat-title">Shipped</div>
        <div class="stat-value text-2xl">{{ number_format($data['ship_total']) }}</div>
        <div class="stat-desc">Completed ship sessions</div>
    </div>
</div>

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Day</th>
                <th>Receive</th>
                <th>Ship</th>
                <th>Volume</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['days'] as $row)
                <tr>
                    <td class="whitespace-nowrap">{{ $row['date'] }}</td>
                    <td>{{ number_format($row['receive']) }}</td>
                    <td>{{ number_format($row['ship']) }}</td>
                    <td class="min-w-40">
                        <progress
                            class="progress progress-primary"
                            value="{{ $row['receive'] + $row['ship'] }}"
                            max="{{ $max }}"
                        ></progress>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="opacity-70">No completed sessions in this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
