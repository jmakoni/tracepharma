@php
    /** @var array{allowed: int, blocked: int, deferred: int, unavailable: int, total: int} $data */
    $total = max(1, $data['total']);
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">Allowed</div>
        <div class="stat-value text-success text-2xl">{{ number_format($data['allowed']) }}</div>
        <div class="stat-desc">{{ number_format(($data['allowed'] / $total) * 100, 1) }}%</div>
    </div>
    <div class="stat">
        <div class="stat-title">Blocked</div>
        <div class="stat-value text-error text-2xl">{{ number_format($data['blocked']) }}</div>
        <div class="stat-desc">Failed or suspect</div>
    </div>
    <div class="stat">
        <div class="stat-title">Deferred</div>
        <div class="stat-value text-warning text-2xl">{{ number_format($data['deferred']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">Unavailable</div>
        <div class="stat-value text-2xl">{{ number_format($data['unavailable']) }}</div>
    </div>
</div>

@if ($data['total'] === 0)
    <p class="text-sm opacity-70">No verifications in this range.</p>
@endif
