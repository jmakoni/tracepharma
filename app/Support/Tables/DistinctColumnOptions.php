<?php

namespace App\Support\Tables;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DistinctColumnOptions
{
    /**
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    public static function of(string $model, string $column, int $limit = 250, ?callable $constrain = null): array
    {
        $query = $model::query()
            ->whereNotNull($column)
            ->where($column, '!=', '');

        if ($constrain !== null) {
            $constrain($query);
        }

        $values = $query->distinct()->orderBy($column)->limit($limit)->pluck($column);

        $options = [];

        foreach ($values as $value) {
            [$key, $label] = self::normalize($value);

            if ($key === '') {
                continue;
            }

            $options[$key] = $label;
        }

        return $options;
    }

    /**
     * @param  class-string<Model>  $owner
     * @param  class-string<Model>  $related
     * @return array<int|string, string>
     */
    public static function related(
        string $owner,
        string $foreignKey,
        string $related,
        string $labelColumn = 'name',
        int $limit = 250,
    ): array {
        $ids = $owner::query()
            ->whereNotNull($foreignKey)
            ->distinct()
            ->limit($limit)
            ->pluck($foreignKey);

        $relatedModel = new $related;
        $keyName = $relatedModel->getKeyName();

        return $related::query()
            ->whereIn($keyName, $ids)
            ->orderBy($labelColumn)
            ->pluck($labelColumn, $keyName)
            ->map(fn (mixed $label): string => (string) $label)
            ->all();
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, string>
     */
    public static function enum(string $enum): array
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            $label = method_exists($case, 'label')
                ? $case->label()
                : (method_exists($case, 'badgeLabel') ? $case->badgeLabel() : $case->name);

            $options[(string) $case->value] = (string) $label;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function boolean(string $true = 'Yes', string $false = 'No'): array
    {
        return [
            '1' => $true,
            '0' => $false,
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    public static function sitePair(
        string $model,
        string $nameColumn,
        string $glnColumn,
        int $limit = 250,
        ?callable $constrain = null,
    ): array {
        $query = $model::query()
            ->select([$nameColumn, $glnColumn])
            ->where(function ($query) use ($nameColumn, $glnColumn): void {
                $query->where(function ($named) use ($nameColumn): void {
                    $named->whereNotNull($nameColumn)->where($nameColumn, '!=', '');
                })->orWhere(function ($gln) use ($glnColumn): void {
                    $gln->whereNotNull($glnColumn)->where($glnColumn, '!=', '');
                });
            });

        if ($constrain !== null) {
            $constrain($query);
        }

        $options = [];

        foreach ($query->groupBy($nameColumn, $glnColumn)->orderBy($nameColumn)->limit($limit)->get() as $row) {
            $name = trim((string) ($row->getAttribute($nameColumn) ?? ''));
            $gln = trim((string) ($row->getAttribute($glnColumn) ?? ''));
            $value = $gln !== '' ? $gln : $name;
            $label = $name !== '' ? $name : $gln;

            if ($value === '') {
                continue;
            }

            $options[$value] = $label;
        }

        natcasesort($options);

        return $options;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<Model>
     */
    public static function applyHavingRange(Builder $query, array $data, string $alias): Builder
    {
        $from = $data['from'] ?? null;
        $until = $data['until'] ?? null;

        if (filled($from)) {
            $query->having($alias, '>=', $from);
        }

        if (filled($until)) {
            $query->having($alias, '<=', $until);
        }

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<Model>
     */
    public static function applyHavingDate(Builder $query, array $data, string $alias): Builder
    {
        $from = $data['from'] ?? null;
        $until = $data['until'] ?? null;

        if (filled($from)) {
            $query->having($alias, '>=', $from);
        }

        if (filled($until)) {
            $query->having($alias, '<=', $until);
        }

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<Model>
     */
    public static function applySiteFilter(
        Builder $query,
        array $data,
        string $nameColumn,
        string $glnColumn,
    ): Builder {
        $values = array_values(array_filter(
            (array) ($data['values'] ?? $data['value'] ?? []),
            fn (mixed $value): bool => filled($value),
        ));

        if ($values === []) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($values, $nameColumn, $glnColumn): void {
            $inner->whereIn($glnColumn, $values)
                ->orWhereIn($nameColumn, $values);
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function normalize(mixed $value): array
    {
        if ($value instanceof BackedEnum) {
            $label = method_exists($value, 'label') ? $value->label() : (string) $value->value;

            return [(string) $value->value, (string) $label];
        }

        if (is_bool($value)) {
            return [$value ? '1' : '0', $value ? 'Yes' : 'No'];
        }

        $string = trim((string) $value);

        return [$string, $string];
    }
}
