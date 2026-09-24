<?php

namespace Database\Seeders;

use App\Models\AppMenuLink;
use App\Support\Marketing\LegalDocumentUrls;
use Illuminate\Database\Seeder;
use NoteBrainsLab\FilamentMenuManager\Models\Menu;
use NoteBrainsLab\FilamentMenuManager\Models\MenuItem;
use NoteBrainsLab\FilamentMenuManager\Models\MenuLocation;

/**
 * Seeds the tenant Footer menu with a short account/help row plus legal links.
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
        ['path' => '/onboarding-wizard', 'title' => 'Getting started', 'icon' => 'heroicon-o-rocket-launch'],
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

        $supportEmail = (string) config('tracepharma.platform_support_email', 'support@tracepharma.io');

        $customItems = [
            ['title' => 'Documentation', 'url' => '/help', 'target' => '_self', 'icon' => 'heroicon-o-book-open'],
            ['title' => 'Legal', 'url' => LegalDocumentUrls::legalSummaryUrl(), 'target' => '_blank', 'icon' => 'heroicon-o-scale'],
            ['title' => 'Terms of Service', 'url' => LegalDocumentUrls::termsUrl(), 'target' => '_blank', 'icon' => 'heroicon-o-document-text'],
            ['title' => 'Privacy Policy', 'url' => LegalDocumentUrls::privacyUrl(), 'target' => '_blank', 'icon' => 'heroicon-o-shield-check'],
            ['title' => 'Support', 'url' => 'mailto:'.$supportEmail, 'target' => '_self', 'icon' => 'heroicon-o-lifebuoy'],
        ];

        foreach ($customItems as $item) {
            MenuItem::query()->create([
                'menu_id' => $menu->id,
                'parent_id' => null,
                'title' => $item['title'],
                'url' => $item['url'],
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
