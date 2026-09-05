<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Filament\App\Pages\ApiTokens;
use App\Filament\App\Pages\IntegrationHealth;
use App\Filament\App\Pages\OrganizationSettings;
use App\Models\User;
use App\Support\SanctumAbilities;
use App\Support\TenantSettings;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * In-app wholesaler WMS certification checklist for ship-confirm pilots.
 */
final class WholesalerIntegrationPack
{
    /**
     * @return list<array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}>
     */
    public function items(): array
    {
        return [
            $this->item(
                id: 'wms_bridge_key',
                title: 'WMS bridge API key',
                description: 'Set in Organization settings → WMS ship-confirm bridge for webhook auth (X-Wms-Api-Key).',
                done: filled(TenantSettings::forTenant(tenant())->wmsBridgeApiKey()),
                href: $this->pageUrl(OrganizationSettings::class),
                actionLabel: 'Organization',
            ),
            $this->item(
                id: 'wms_token',
                title: 'API token with wms:ship-confirm',
                description: 'Issue a Sanctum token for the Connector path when middleware prefers Bearer auth.',
                done: $this->hasTokenAbility(SanctumAbilities::WMS_SHIP_CONFIRM),
                href: $this->pageUrl(ApiTokens::class),
                actionLabel: 'API tokens',
            ),
            $this->item(
                id: 'outbound_epcis_token',
                title: 'API token with epcis:transmit (optional)',
                description: 'Required when your middleware POSTs outbound EPCIS XML directly after ship-confirm.',
                done: $this->hasTokenAbility(SanctumAbilities::EPCIS_TRANSMIT),
                href: $this->pageUrl(ApiTokens::class),
                actionLabel: 'API tokens',
            ),
            $this->item(
                id: 'integration_health',
                title: 'Integration Health reachable',
                description: 'Done means the Integration Health page is accessible for a pre-cutover review—not that a baseline was already recorded.',
                done: IntegrationHealth::canAccess(),
                href: $this->pageUrl(IntegrationHealth::class),
                actionLabel: 'Integration health',
            ),
            $this->item(
                id: 'postman_docs',
                title: 'Postman collection + guide reviewed',
                description: 'Manual step: import docs/integrations/postman/tracepharma-wms-ship-confirm.json and walk docs/integrations/wms.md. Not auto-scored.',
                done: false,
                href: $this->pageUrl(ApiTokens::class),
                actionLabel: 'API tokens',
            ),
            $this->item(
                id: 'kill_switch',
                title: 'WMS webhooks enabled',
                description: 'Admin kill switch “Block WMS ship-confirm webhooks” must be off for production traffic.',
                done: ! TenantSettings::forTenant(tenant())->wmsWebhooksKilled(),
                href: $this->pageUrl(IntegrationHealth::class),
                actionLabel: 'Integration health',
            ),
        ];
    }

    public function score(): int
    {
        $items = array_values(array_filter(
            $this->items(),
            fn (array $item): bool => ($item['id'] ?? '') !== 'postman_docs',
        ));

        if ($items === []) {
            return 0;
        }

        $done = count(array_filter($items, fn (array $item): bool => $item['done']));

        return (int) round(($done / count($items)) * 100);
    }

    /**
     * @return array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}
     */
    private function item(
        string $id,
        string $title,
        string $description,
        bool $done,
        ?string $href,
        string $actionLabel,
    ): array {
        $item = [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'done' => $done,
            'action_label' => $actionLabel,
        ];

        if ($href !== null) {
            $item['href'] = $href;
        }

        return $item;
    }

    private function hasTokenAbility(string $ability): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereJsonContains('abilities', $ability)
            ->exists();
    }

    /**
     * @param  class-string  $page
     */
    private function pageUrl(string $page): ?string
    {
        try {
            if (! $page::canAccess()) {
                return null;
            }

            return $page::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
