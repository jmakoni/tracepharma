<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\App\Pages\ApiTokens;
use App\Filament\App\Pages\OrganizationSettings;
use App\Filament\App\Pages\VerifyProduct;
use App\Models\User;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\SanctumAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * In-app PMS certification checklist for dispense-check pilots.
 */
final class PmsIntegrationChecklist
{
    /**
     * @return list<array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}>
     */
    public function items(): array
    {
        return [
            $this->item(
                id: 'receiving_state',
                title: 'ATP evaluation jurisdictions',
                description: 'Org facility jurisdictions (or preferred receiving state fallback) so ATP and verification context are defined.',
                done: AtpLicenseRelevance::evaluationJurisdictionKeys() !== [],
                href: $this->pageUrl(OrganizationSettings::class),
                actionLabel: 'Organization',
            ),
            $this->item(
                id: 'dispense_token',
                title: 'API token with vrs:dispense-check',
                description: 'Issue a Sanctum token that includes the dispense-check ability.',
                done: $this->hasDispenseCheckToken(),
                href: $this->pageUrl(ApiTokens::class),
                actionLabel: 'API tokens',
            ),
            $this->item(
                id: 'verify_workstation',
                title: 'Verify Product workstation reachable',
                description: 'Done means staff can open Verify Product as a PMS fallback—not that a live dispense-check was proven.',
                done: VerifyProduct::canAccess(),
                href: $this->pageUrl(VerifyProduct::class),
                actionLabel: 'Verify Product',
            ),
            $this->item(
                id: 'postman_docs',
                title: 'Postman collection + guide reviewed',
                description: 'Manual step: import docs/integrations/postman/tracepharma-pms-dispense-check.json and walk docs/integrations/pms.md. Not auto-scored.',
                done: false,
                href: $this->pageUrl(ApiTokens::class),
                actionLabel: 'API tokens',
            ),
            $this->item(
                id: 'vendor_runbook',
                title: 'Named PMS vendor runbook followed',
                description: 'Manual step: map your PMS webhook to POST /api/v1/dispense-check using docs/integrations/pms/pioneerrx.md, bestrx.md, primerx.md, liberty-rx30.md, or qs1.md. No /api/v1/pms/{vendor}/dispense routes. Not auto-scored.',
                done: false,
                href: $this->pageUrl(ApiTokens::class),
                actionLabel: 'API tokens',
            ),
        ];
    }

    public function score(): int
    {
        $manualIds = ['postman_docs', 'vendor_runbook'];
        $items = array_values(array_filter(
            $this->items(),
            fn (array $item): bool => ! in_array($item['id'] ?? '', $manualIds, true),
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

    private function hasDispenseCheckToken(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereJsonContains('abilities', SanctumAbilities::VRS_DISPENSE_CHECK)
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
