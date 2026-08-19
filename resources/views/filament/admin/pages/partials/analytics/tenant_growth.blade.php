@php
    /** @var array{days: list<array{date: string, demo: int, stage: int, prod: int, unset: int, total: int}>, total: int, by_environment: list<array{environment: string, label: string, count: int}>} $data */
    $max = max([1, ...array_column($data['days'], 'total')]);
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">Tenants created</div>
        <div class="stat-value text-2xl">{{ number_format($data['total']) }}</div>
        <div class="stat-desc">In this range</div>
    </div>
    @foreach ($data['by_environment'] as $environment)
        <div class="stat">
            <div class="stat-title">{{ $environment['label'] }}</div>
            <div class="stat-value text-2xl">{{ number_format($environment['count']) }}</div>
        </div>
    @endforeach
</div>

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Day</th>
                <th>Demo</th>
                <th>Stage</th>
                <th>Prod</th>
                <th>Unset</th>
                <th>Volume</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['days'] as $row)
                <tr>
                    <td class="whitespace-nowrap">{{ $row['date'] }}</td>
                    <td>{{ number_format($row['demo']) }}</td>
                    <td>{{ number_format($row['stage']) }}</td>
                    <td>{{ number_format($row['prod']) }}</td>
                    <td>{{ number_format($row['unset']) }}</td>
                    <td class="min-w-40">
                        <progress class="progress progress-primary" value="{{ $row['total'] }}" max="{{ $max }}"></progress>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="opacity-70">No tenants created in this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
