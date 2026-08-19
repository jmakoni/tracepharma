@php
    /** @var array{days: list<array{date: string, success: int, partial: int, failed: int}>, sources: list<array{source: string, label: string, success: int, partial: int, failed: int, total: int}>, success: int, partial: int, failed: int} $data */
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">Success</div>
        <div class="stat-value text-success text-2xl">{{ number_format($data['success']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">Partial</div>
        <div class="stat-value text-warning text-2xl">{{ number_format($data['partial']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">Failed</div>
        <div class="stat-value text-error text-2xl">{{ number_format($data['failed']) }}</div>
    </div>
</div>

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Source</th>
                <th>Success</th>
                <th>Partial</th>
                <th>Failed</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['sources'] as $source)
                <tr>
                    <td>{{ $source['label'] }}</td>
                    <td>{{ number_format($source['success']) }}</td>
                    <td>
                        @if ($source['partial'] > 0)
                            <span class="badge badge-warning badge-outline">{{ number_format($source['partial']) }}</span>
                        @else
                            0
                        @endif
                    </td>
                    <td>
                        @if ($source['failed'] > 0)
                            <span class="badge badge-error badge-outline">{{ number_format($source['failed']) }}</span>
                        @else
                            0
                        @endif
                    </td>
                    <td>{{ number_format($source['total']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="opacity-70">No import runs in this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
