<?php

namespace Tracepharma\FilamentUiExtras\Concerns\Widgets;

trait HasStatsOverviewSkeleton
{
    public function placeholder(array $params = []): mixed
    {
        return view('filament-ui-extras::components.skeletons.stats-overview', [
            'count' => $this->getPlaceholderStatCount(),
            'columns' => $this->resolvePlaceholderColumns(),
        ]);
    }

    protected function getPlaceholderStatCount(): int
    {
        $columns = $this->resolvePlaceholderColumns();

        if (is_int($columns)) {
            return max(1, $columns);
        }

        if (is_array($columns)) {
            $values = array_filter($columns, static fn ($value): bool => is_int($value) && $value > 0);

            if ($values !== []) {
                return max(1, (int) max($values));
            }
        }

        return 3;
    }

    /**
     * @return int | array<string, ?int> | null
     */
    protected function resolvePlaceholderColumns(): int|array|null
    {
        if (property_exists($this, 'columns') && $this->columns !== null) {
            return $this->columns;
        }

        if (method_exists($this, 'getColumns')) {
            return $this->getColumns();
        }

        return 3;
    }
}
