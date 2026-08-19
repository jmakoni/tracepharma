@php
    /** @var array{days: list<array{date: string, organizations: int, products: int, total: int}>, organizations_total: int, products_total: int, total: int} $data */
    $max = max([1, ...array_column($data['days'], 'total')]);
@endphp

<div class="stats stats-vertical sm:stats-horizontal bg-base-200 shadow w-full">
    <div class="stat">
        <div class="stat-title">Registry volume</div>
        <div class="stat-value text-2xl">{{ number_format($data['total']) }}</div>
        <div class="stat-desc">In this range</div>
    </div>
    <div class="stat">
        <div class="stat-title">Organizations</div>
        <div class="stat-value text-2xl">{{ number_format($data['organizations_total']) }}</div>
    </div>
    <div class="stat">
        <div class="stat-title">Products</div>
        <div class="stat-value text-2xl">{{ number_format($data['products_total']) }}</div>
    </div>
</div>

<div class="overflow-x-auto">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Day</th>
                <th>Organizations</th>
                <th>Products</th>
                <th>Volume</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($data['days'] as $row)
                <tr>
                    <td class="whitespace-nowrap">{{ $row['date'] }}</td>
                    <td>{{ number_format($row['organizations']) }}</td>
                    <td>{{ number_format($row['products']) }}</td>
                    <td class="min-w-40">
                        <progress class="progress progress-primary" value="{{ $row['total'] }}" max="{{ $max }}"></progress>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="opacity-70">No registry records created in this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
