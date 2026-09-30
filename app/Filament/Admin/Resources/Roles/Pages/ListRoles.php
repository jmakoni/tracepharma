<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Resources\Roles\RoleResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListRoles extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
