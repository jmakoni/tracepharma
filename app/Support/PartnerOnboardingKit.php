<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PartnerType;
use App\Filament\App\Pages\InviteTradingPartner;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\Receiving\ReceivingSession;
use App\Models\TradingPartner;
use App\Services\Outbound\CustomerPortalService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Guided steps to connect an upstream supplier and prove first receive.
 */
final class PartnerOnboardingKit
{
    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     description: string,
     *     done: bool,
     *     href?: string,
     *     action_label?: string
     * }>
     */
    public function steps(): array
    {
        return [
            $this->step(
                id: 'create_partner',
                title: 'Create trading partner',
                description: 'Add the supplier GLN and contact email. Use Invite partner to create the partner and an inbound route in one step.',
                done: $this->hasUpstreamPartnerWithGln(),
                href: $this->pageUrl(InviteTradingPartner::class) ?? $this->resourceIndexUrl(TradingPartnerResource::class),
                actionLabel: 'Invite partner',
            ),
            $this->step(
                id: 'inbound_connection',
                title: 'Configure inbound path',
                description: 'Activate HTTPS webhook, SFTP poll, or hub routing so EPCIS can arrive before receiving.',
                done: $this->hasActiveInboundConnection(),
                href: $this->resourceIndexUrl(InboundConnectionResource::class),
                actionLabel: 'Inbound connections',
            ),
            $this->step(
                id: 'test_inbound',
                title: 'Validate test EPCIS',
                description: 'Confirm at least one inbound document reached parsed or validated status (upload, webhook, or hub).',
                done: $this->hasValidatedInboundDocument(),
                href: $this->resourceIndexUrl(\App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource::class),
                actionLabel: 'Inbound EPCIS',
            ),
            $this->step(
                id: 'first_receive',
                title: 'Complete first receive',
                description: 'Open a receiving session, confirm scans, and complete so custody and last-seen are proven.',
                done: $this->hasCompletedReceive(),
                href: $this->resourceIndexUrl(ReceivingSessionResource::class),
                actionLabel: 'Receiving',
            ),
            $this->step(
                id: 'downstream_portal',
                title: 'Issue customer portal (optional)',
                description: 'For pharmacy or dispenser customers without AS2, copy a signed portal link after you ship TI.',
                done: $this->hasDownstreamPortalReady(),
                href: $this->resourceIndexUrl(TradingPartnerResource::class),
                actionLabel: 'Trading partners',
            ),
        ];
    }

    public function score(): int
    {
        $steps = $this->steps();

        if ($steps === []) {
            return 0;
        }

        $done = count(array_filter($steps, fn (array $step): bool => $step['done']));

        return (int) round(($done / count($steps)) * 100);
    }

    public function isComplete(): bool
    {
        foreach ($this->steps() as $step) {
            if ($step['id'] === 'downstream_portal') {
                continue;
            }

            if (! $step['done']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Plain-text IT handoff for email or PDF copy.
     */
    public function exportBrief(): string
    {
        $tenant = tenant();
        $name = $tenant?->name ?? 'TracePharma tenant';
        $domain = $tenant?->domains()->first()?->domain ?? '(your tenant domain)';

        $lines = [
            "TracePharma partner onboarding — {$name}",
            '',
            'Send EPCIS 1.2 (DSCSA) to this tenant using one of:',
            "- HTTPS webhook (per inbound connection on {$domain})",
            '- SFTP poll (configure host credentials after invite)',
            '- EPCIS hub (Systech / UniTrace) when enabled by TracePharma ops',
            '',
            'Checklist:',
        ];

        foreach ($this->steps() as $step) {
            $mark = $step['done'] ? '[x]' : '[ ]';
            $lines[] = "{$mark} {$step['title']}";
        }

        $lines[] = '';
        $lines[] = 'After first validated inbound file, complete receiving in the App panel.';

        return implode("\n", $lines);
    }

    /**
     * @return array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}
     */
    private function step(
        string $id,
        string $title,
        string $description,
        bool $done,
        ?string $href,
        string $actionLabel,
    ): array {
        $step = [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'done' => $done,
            'action_label' => $actionLabel,
        ];

        if ($href !== null) {
            $step['href'] = $href;
        }

        return $step;
    }

    private function hasUpstreamPartnerWithGln(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        return TradingPartner::query()
            ->where('is_active', true)
            ->whereIn('partner_type', [PartnerType::Manufacturer->value, PartnerType::Wholesaler->value])
            ->whereNotNull('gln')
            ->where('gln', '!=', '')
            ->exists();
    }

    private function hasActiveInboundConnection(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        return InboundConnection::query()->where('is_active', true)->exists();
    }

    private function hasValidatedInboundDocument(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        return EpcisDocument::query()
            ->where('direction', 'inbound')
            ->whereIn('status', ['parsed', 'validated'])
            ->exists();
    }

    private function hasCompletedReceive(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        return ReceivingSession::query()
            ->where('status', 'completed')
            ->whereNotNull('site_id')
            ->exists();
    }

    private function hasDownstreamPortalReady(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $pharmacyPartner = TradingPartner::query()
            ->where('is_active', true)
            ->where('partner_type', PartnerType::Pharmacy->value)
            ->whereNotNull('customer_portal_uuid')
            ->first();

        if ($pharmacyPartner === null) {
            return false;
        }

        try {
            app(CustomerPortalService::class)->signedCustomerPortalUrl($pharmacyPartner);

            return true;
        } catch (Throwable) {
            return false;
        }
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

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private function resourceIndexUrl(string $resource): ?string
    {
        try {
            $panel = Filament::getPanel('app');
            $name = $resource::getRouteBaseName($panel).'.index';

            if (! Route::has($name)) {
                return null;
            }

            if (! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('index', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
