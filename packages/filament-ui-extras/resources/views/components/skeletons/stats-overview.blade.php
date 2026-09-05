@props([
    'count' => 3,
    'columns' => 3,
])

@php
    $cols = is_int($columns) ? $columns : (is_array($columns) ? (int) (max(array_filter($columns, 'is_int')) ?: 3) : 3);
    $cols = max(1, min($cols, 6));
    $count = max(1, (int) $count);
@endphp

<div
    class="fi-uie-stats-skeleton fi-uie-skeleton-pulse"
    style="--fi-uie-stat-cols: {{ $cols }}"
    role="status"
    aria-busy="true"
    aria-label="{{ __('filament-ui-extras::ui.loading') }}"
>
    @foreach (range(1, $count) as $i)
        <div class="fi-uie-stats-skeleton-card">
            <div class="fi-uie-stats-skeleton-line fi-uie-stats-skeleton-line--md"></div>
            <div class="fi-uie-stats-skeleton-line fi-uie-stats-skeleton-line--lg"></div>
            <div class="fi-uie-stats-skeleton-line fi-uie-stats-skeleton-line--sm"></div>
        </div>
    @endforeach
</div>
