<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

use App\Actions\Portal\EnsurePortalOrganization;
use App\Models\Epcis\EpcisDocument;
use App\Models\OutboundConnection;
use App\Models\PortalPublication;
use App\Models\TradingPartner;
use App\Notifications\PortalPublicationReadyNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Portal transport: publish EPCIS into the buyer client-portal visibility ledger.
 */
final class PortalOutboundSender
{
    public function __construct(
        private readonly EnsurePortalOrganization $ensurePortalOrganization,
    ) {}

    public function send(
        OutboundConnection $connection,
        EpcisDocument $document,
    ): void {
        $partnerId = $document->trading_partner_id !== null
            ? (int) $document->trading_partner_id
            : ($connection->trading_partner_id !== null ? (int) $connection->trading_partner_id : null);

        if ($partnerId === null) {
            throw new RuntimeException(
                'Client portal outbound requires a trading partner on the document or connection.',
            );
        }

        $partner = TradingPartner::query()->find($partnerId);
        if ($partner === null || ! $partner->is_active) {
            throw new RuntimeException('Client portal outbound trading partner is missing or inactive.');
        }

        if (! Schema::hasTable('portal_publications')) {
            // Phase 1: transport succeeds so ladder/explicit portal pins can complete
            // before the OTP portal schema ships. Phase 2 persists publications.
            return;
        }

        $this->ensurePortalOrganization->handle($partner);

        // Unique (document, partner): one row; republish clears revoked_at.
        $existing = PortalPublication::query()
            ->where('epcis_document_id', $document->getKey())
            ->where('trading_partner_id', $partnerId)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'published_at' => now(),
                'published_by_connection_id' => $connection->getKey(),
                'revoked_at' => null,
            ])->save();
        } else {
            PortalPublication::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $partnerId,
                'published_at' => now(),
                'published_by_connection_id' => $connection->getKey(),
                'revoked_at' => null,
            ]);
        }

        $this->maybeNotify($connection, $document, $partner);
    }

    private function maybeNotify(
        OutboundConnection $connection,
        EpcisDocument $document,
        TradingPartner $partner,
    ): void {
        $settings = is_array($connection->settings) ? $connection->settings : [];

        if (! (bool) data_get($settings, 'notify_on_publish', false)) {
            return;
        }

        $emails = $this->notifyEmails($settings, $partner);

        if ($emails === []) {
            return;
        }

        $notification = new PortalPublicationReadyNotification(
            tenantLabel: (string) (tenant()?->name ?? 'Your trading partner'),
            loginUrl: route('tenant.client-portal.login'),
            asnNumber: filled($document->asn_number) ? (string) $document->asn_number : null,
            customerPo: filled($document->customer_po) ? (string) $document->customer_po : null,
        );

        foreach ($emails as $email) {
            Notification::route('mail', $email)->notify($notification);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function notifyEmails(array $settings, TradingPartner $partner): array
    {
        $emails = [];

        $inviteEmails = data_get($settings, 'invite_emails', []);
        if (is_array($inviteEmails)) {
            foreach ($inviteEmails as $entry) {
                if (is_string($entry) && filled($entry)) {
                    $emails[] = strtolower(trim($entry));
                } elseif (is_array($entry) && filled($entry['email'] ?? null)) {
                    $emails[] = strtolower(trim((string) $entry['email']));
                }
            }
        }

        if (filled($partner->email)) {
            $emails[] = strtolower(trim((string) $partner->email));
        }

        return array_values(array_unique(array_filter(
            $emails,
            fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }
}
