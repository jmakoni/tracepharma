<?php

namespace Tracepharma\FilamentTableViews;

use Filament\Contracts\Plugin;
use Filament\Panel;

class FilamentTableViewsPlugin implements Plugin
{
    protected string $sharePermission = 'users.manage';

    public static function make(): static
    {
        return app(static::class);
    }

    public static function tryGet(): ?static
    {
        try {
            /** @var static|null $plugin */
            $plugin = filament()->getPlugin('filament-table-views');

            return $plugin;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function get(): static
    {
        $plugin = static::tryGet();

        if (! $plugin) {
            throw new \RuntimeException('FilamentTableViewsPlugin is not registered on the current panel.');
        }

        return $plugin;
    }

    public function getId(): string
    {
        return 'filament-table-views';
    }

    public function sharePermission(string $permission): static
    {
        $this->sharePermission = $permission;

        return $this;
    }

    public function getSharePermission(): string
    {
        return $this->sharePermission;
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
