<?php

namespace App\Support\Menus;

use NoteBrainsLab\FilamentMenuManager\MenuManager;
use NoteBrainsLab\FilamentMenuManager\Models\Menu;
use Throwable;

/**
 * Loads the central Menu Manager "footer" location for tenant App panel rendering.
 */
final class TenantFooterMenu
{
    /**
     * @return list<array{title: string, url: string, target: string, icon: ?string}>
     */
    public static function items(): array
    {
        try {
            $callback = function (): array {
                $menus = app(MenuManager::class)->menusForLocation('footer');

                /** @var Menu|null $menu */
                $menu = $menus->first(fn (Menu $candidate): bool => (bool) $candidate->is_active)
                    ?? $menus->first();

                if ($menu === null) {
                    return [];
                }

                return $menu->rootItems()
                    ->where('enabled', true)
                    ->with('linkable')
                    ->orderBy('order')
                    ->get()
                    ->map(static function ($item): array {
                        return [
                            'title' => $item->getResolvedTitle(),
                            'url' => $item->getResolvedUrl(),
                            'target' => filled($item->target) ? (string) $item->target : '_self',
                            'icon' => $item->icon,
                        ];
                    })
                    ->values()
                    ->all();
            };

            if (function_exists('tenancy') && tenancy()->initialized) {
                return tenancy()->central($callback);
            }

            return $callback();
        } catch (Throwable) {
            return [];
        }
    }
}
