<?php

namespace App\Filament\Admin\Support;

use App\Models\Fda\FdaOrganization;
use Filament\Forms\Components\Select;

final class FdaOrganizationSelect
{
    public static function make(bool $required = true): Select
    {
        $select = Select::make('fda_organization_id')
            ->label('Organization')
            ->searchable()
            ->preload(false)
            ->searchDebounce(500)
            ->native(false)
            ->getSearchResultsUsing(fn (?string $search): array => self::search($search))
            ->getOptionLabelUsing(fn ($value): ?string => self::labelFor($value));

        return $required ? $select->required() : $select->nullable();
    }

    /**
     * @return array<int, string>
     */
    public static function search(?string $search): array
    {
        if (blank($search)) {
            return [];
        }

        return FdaOrganization::query()
            ->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('canonical_name', 'like', "%{$search}%")
                    ->orWhere('original_name', 'like', "%{$search}%")
                    ->orWhere('duns_number', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (FdaOrganization $org): array => [
                (int) $org->id => $org->name ?: $org->canonical_name,
            ])
            ->all();
    }

    public static function labelFor(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $org = FdaOrganization::query()->find($value);

        if (! $org instanceof FdaOrganization) {
            return null;
        }

        return $org->name ?: $org->canonical_name;
    }
}
