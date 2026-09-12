<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use NoteBrainsLab\FilamentMenuManager\Concerns\HasMenuItems;

/**
 * Central catalog of Filament App panel pages/resources for the menu manager.
 * Paths are tenant-relative (e.g. /operations-hub) so the same item works on every tenant host.
 */
class AppMenuLink extends Model
{
    use HasMenuItems;

    protected $fillable = [
        'key',
        'label',
        'path',
        'icon',
        'navigation_group',
        'source_class',
        'sort',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function getMenuLabel(): string
    {
        $group = trim((string) ($this->navigation_group ?? ''));

        if ($group !== '') {
            return $group.' · '.$this->label;
        }

        return (string) $this->label;
    }

    public function getMenuUrl(): string
    {
        $path = trim((string) $this->path);

        if ($path === '') {
            return '#';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    public function getMenuIcon(): ?string
    {
        return $this->icon;
    }
}
