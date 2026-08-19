@php
    /** @var array{statuses: list<array{key: string, label: string, count: int}>, total: int, provisioned: int, average_days_to_provisioned: float|null} $data */
    $max = max([1, ...array_column($data['statuses'], 'count')]);
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">Onboardings</div>
        <div class="stat-value text-2xl">{{ number_format($data['total']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">Provisioned</div>
        <div class="stat-value text-success text-2xl">{{ number_format($data['provisioned']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">Time to provisioned</div>
        <div class="stat-value text-2xl">
            {{ $data['average_days_to_provisioned'] === null ? '—' : number_format($data['average_days_to_provisioned'], 1) }}
        </div>
        <div class="stat-desc">Average days</div>
    </div>
</div>

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Status</th>
                <th>Count</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['statuses'] as $status)
                <tr>
                    <td>{{ $status['label'] }}</td>
                    <td>{{ number_format($status['count']) }}</td>
                    <td class="min-w-32">
                        <progress class="progress progress-primary" value="{{ $status['count'] }}" max="{{ $max }}"></progress>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
