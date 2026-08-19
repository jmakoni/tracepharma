@php
    /** @var array{days: list<array{date: string, count: int}>, total: int} $data */
    $max = max([1, ...array_column($data['days'], 'count')]);
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">Demo requests</div>
        <div class="stat-value text-2xl">{{ number_format($data['total']) }}</div>
        <div class="stat-desc">In this range</div>
    </div>
</div>

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Day</th>
                <th>Count</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['days'] as $row)
                <tr>
                    <td class="whitespace-nowrap">{{ $row['date'] }}</td>
                    <td>{{ number_format($row['count']) }}</td>
                    <td class="min-w-40">
                        <progress class="progress progress-primary" value="{{ $row['count'] }}" max="{{ $max }}"></progress>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="opacity-70">No demo requests in this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
