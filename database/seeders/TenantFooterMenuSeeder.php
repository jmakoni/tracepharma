<?php

namespace Database\Seeders;

use App\Models\AppMenuLink;
use Illuminate\Database\Seeder;
use NoteBrainsLab\FilamentMenuManager\Models\Menu;
use NoteBrainsLab\FilamentMenuManager\Models\MenuItem;
use NoteBrainsLab\FilamentMenuManager\Models\MenuLocation;

/**
 * Seeds the tenant Footer menu with secondary legal/help/account links.
 */
class TenantFooterMenuSeeder extends Seeder
{
    /**
     * App menu link paths (tenant-relative) for model-backed items.
     *
     * @var list<array{path: string, title: string, icon: string|null}>
     */
    private const APP_ITEMS = [
        ['path' => '/settings-hub', 'title' => 'Settings', 'icon' => 'heroicon-o-cog-6-tooth'],
        ['path' => '/my-profile', 'title' => 'My profile', 'icon' => 'heroicon-o-user-circle'],
        ['path' => '/dashboard-preferences', 'title' => 'My dashboard', 'icon' => 'heroicon-o-squares-2x2'],
        ['path' => '/onboarding-wizard', 'title' => 'Getting started', 'icon' => 'heroicon-o-rocket-launch'],
        ['path' => '/accept-legal-documents', 'title' => 'Legal documents', 'icon' => 'heroicon-o-document-check'],
        ['path' => '/integration-health', 'title' => 'Integration health', 'icon' => 'heroicon-o-signal'],
        ['path' => '/compliance-alert-center', 'title' => 'Alert center', 'icon' => 'heroicon-o-bell-alert'],
    ];

    /**
     * Custom (non-catalog) footer links.
     *
     * @var list<array{title: string, url: string, target: string, icon: string|null}>
     */
    private const CUSTOM_ITEMS = [
        ['title' => 'Documentation', 'url' => '/help', 'target' => '_self', 'icon' => 'heroicon-o-book-open'],
        ['title' => 'Terms of Service', 'url' => 'https://tracepharma.io/tos', 'target' => '_blank', 'icon' => 'heroicon-o-document-text'],
        ['title' => 'Privacy Policy', 'url' => 'https://tracepharma.io/privacy', 'target' => '_blank', 'icon' => 'heroicon-o-shield-check'],
        ['title' => 'Support', 'url' => 'mailto:support@tracepharma.io', 'target' => '_self', 'icon' => 'heroicon-o-lifebuoy'],
    ];

    public function run(): void
    {
        $location = MenuLocation::query()->firstOrCreate(
            ['handle' => 'footer'],
            ['name' => 'Footer'],
        );

        $menu = Menu::query()->updateOrCreate(
            [
                'menu_location_id' => $location->id,
                'name' => 'Tenant Footer',
            ],
            [
                'is_active' => true,
            ],
        );

        // Replace footer items so re-seed is idempotent.
        MenuItem::query()->where('menu_id', $menu->id)->delete();

        $order = 0;

        foreach (self::APP_ITEMS as $item) {
            $link = AppMenuLink::query()->where('path', $item['path'])->first();

            if ($link === null) {
                MenuItem::query()->create([
                    'menu_id' => $menu->id,
                    'parent_id' => null,
                    'title' => $item['title'],
                    'url' => $item['path'],
                    'target' => '_self',
                    'icon' => $item['icon'],
                    'type' => 'custom',
                    'order' => ++$order,
                    'enabled' => true,
                ]);

                continue;
            }

            MenuItem::query()->create([
                'menu_id' => $menu->id,
                'parent_id' => null,
                'title' => $item['title'],
                'url' => $link->getMenuUrl(),
                'target' => '_self',
                'icon' => $item['icon'] ?? $link->getMenuIcon(),
                'type' => 'model',
                'linkable_type' => AppMenuLink::class,
                'linkable_id' => $link->id,
                'order' => ++$order,
                'enabled' => true,
            ]);
        }

        foreach (self::CUSTOM_ITEMS as $item) {
            $url = $item['url'];
            if ($item['title'] === 'Support') {
                $email = (string) config('tracepharma.platform_support_email', 'support@tracepharma.io');
                $url = 'mailto:'.$email;
            }

            MenuItem::query()->create([
                'menu_id' => $menu->id,
                'parent_id' => null,
                'title' => $item['title'],
                'url' => $url,
                'target' => $item['target'],
                'icon' => $item['icon'],
                'type' => 'custom',
                'order' => ++$order,
                'enabled' => true,
            ]);
        }

        // Remove the empty placeholder "Footer" menu if it is a different row.
        Menu::query()
            ->where('menu_location_id', $location->id)
            ->where('id', '!=', $menu->id)
            ->where('name', 'Footer')
            ->whereDoesntHave('items')
            ->delete();
    }
}
