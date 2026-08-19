@php
    /** @var array{on_time: int, late: int, pending: int, score_percent: float|null, at_risk: list<array{id: int, title: string, due_at: string|null, overdue: bool}>} $data */
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">On-time SLA</div>
        <div class="stat-value text-2xl">
            {{ $data['score_percent'] === null ? '—' : number_format($data['score_percent'], 1).'%' }}
        </div>
        <div class="stat-desc">Responded before due</div>
    </div>
    <div class="stat">
        <div class="stat-title">On time</div>
        <div class="stat-value text-success text-2xl">{{ number_format($data['on_time']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">Late</div>
        <div class="stat-value text-error text-2xl">{{ number_format($data['late']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">Pending</div>
        <div class="stat-value text-warning text-2xl">{{ number_format($data['pending']) }}</div>
    </div>
</div>

@if ($data['score_percent'] !== null)
    <progress class="progress progress-primary w-full" value="{{ $data['score_percent'] }}" max="100"></progress>
@endif

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>At-risk request</th>
                <th>Due</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['at_risk'] as $request)
                <tr>
                    <td>{{ $request['title'] }}</td>
                    <td>
                        {{ $request['due_at'] ?? '—' }}
                        @if ($request['overdue'])
                            <span class="badge badge-error badge-outline badge-sm">Overdue</span>
                        @endif
                    </td>
                    <td>
                        @if ($url = $this->tracingRequestUrl($request['id']))
                            <a href="{{ $url }}" class="btn btn-ghost btn-xs">Open</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="opacity-70">No open tracing requests with a due date.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
