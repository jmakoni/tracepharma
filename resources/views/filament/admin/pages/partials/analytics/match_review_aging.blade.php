@php
    /** @var array{bands: list<array{key: string, label: string, count: int}>, confidences: list<array{key: string, label: string, count: int}>, total: int} $data */
    $bandMax = max([1, ...array_column($data['bands'], 'count')]);
    $confidenceMax = max([1, ...array_column($data['confidences'], 'count')]);
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">Pending reviews</div>
        <div class="stat-value text-2xl">{{ number_format($data['total']) }}</div>
        <div class="stat-desc">By age and confidence</div>
    </div>
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <div class="overflow-x-auto">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Age</th>
                    <th>Count</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['bands'] as $band)
                    <tr>
                        <td>{{ $band['label'] }}</td>
                        <td>{{ number_format($band['count']) }}</td>
                        <td class="min-w-32">
                            <progress class="progress progress-warning" value="{{ $band['count'] }}" max="{{ $bandMax }}"></progress>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Confidence</th>
                    <th>Count</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['confidences'] as $confidence)
                    <tr>
                        <td>{{ $confidence['label'] }}</td>
                        <td>{{ number_format($confidence['count']) }}</td>
                        <td class="min-w-32">
                            <progress class="progress progress-info" value="{{ $confidence['count'] }}" max="{{ $confidenceMax }}"></progress>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
