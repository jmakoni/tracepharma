<?php

declare(strict_types=1);

namespace App\Actions\Receiving;

use App\Enums\EpcisAuthoredKind;
use App\Enums\EpcisReceivedVia;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\TradingPartner;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dual-gated CMO auto-receive: tenant master + partner is_cmo + auto_receive_inbound.
 * Completes ASN receive without Scan In when both gates are on.
 *
 * Own-product CMOs (`cmo_ownership=own_product` + master on) may omit DSCSA TS;
 * validation records a warning and OpenReceivingSession skips enforce_ts. CMO-sells still requires TS.
 */
final class AutoReceiveCmoInboundDocument
{
    public function __construct(
        private readonly OpenReceivingSessionFromDocument $openReceivingSessionFromDocument,
        private readonly ConfirmReceivingScan $confirmReceivingScan,
        private readonly CompleteReceivingSession $completeReceivingSession,
    ) {}

    public function handle(EpcisDocument $document): ?ReceivingSession
    {
        if (! $this->shouldAutoReceive($document)) {
            return null;
        }

        try {
            $session = $this->openReceivingSessionFromDocument->handle(
                $document,
                asSystem: true,
            );

            $parentLines = ReceivingScanLine::query()
                ->with('epc')
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'parent')
                ->where('status', 'expected')
                ->orderBy('id')
                ->get();

            foreach ($parentLines as $line) {
                $uri = $line->epc?->epc_uri;
                if (! is_string($uri) || $uri === '') {
                    throw new DomainException('Expected parent line is missing an EPC URI.');
                }

                $confirm = $this->confirmReceivingScan->handle(
                    $session->fresh() ?? $session,
                    $uri,
                    userId: null,
                    autoConfirmChildren: true,
                );

                if (! ($confirm['ok'] ?? false)) {
                    throw new DomainException((string) ($confirm['message'] ?? 'Confirm scan failed.'));
                }
            }

            $session = $session->fresh() ?? $session;
            if ($session->status !== 'completed') {
                $session = $this->completeReceivingSession->handle($session);
            }

            Log::info('receiving.auto_cmo_receive', [
                'source' => 'auto_cmo_receive',
                'document_id' => (int) $document->getKey(),
                'session_id' => (int) $session->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => $session->site_id,
            ]);

            return $session->fresh() ?? $session;
        } catch (Throwable $e) {
            Log::warning('receiving.auto_cmo_receive_failed', [
                'source' => 'auto_cmo_receive',
                'document_id' => (int) $document->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function shouldAutoReceive(EpcisDocument $document): bool
    {
        $tenant = tenant();
        if ($tenant === null) {
            return false;
        }

        if ($tenant->profile !== TenantProfile::Manufacturer) {
            return false;
        }

        if (! TenantFeatures::forTenant($tenant)->supportsReceiving()) {
            return false;
        }

        if (! TenantSettings::forTenant($tenant)->autoReceiveFromCmo()) {
            return false;
        }

        if ((string) ($document->direction ?? '') !== 'inbound') {
            return false;
        }

        if ((string) ($document->status ?? '') !== 'validated') {
            return false;
        }

        if ($document->received_via === EpcisReceivedVia::GuardianLotClose) {
            return false;
        }

        $authored = $document->authored_kind;
        if ($authored instanceof EpcisAuthoredKind) {
            return false;
        }

        if ($document->openReceivingSession() !== null) {
            return false;
        }

        if ($document->isFloorReceived()) {
            return false;
        }

        $partnerId = $document->trading_partner_id;
        if ($partnerId === null) {
            return false;
        }

        $partner = TradingPartner::query()->find($partnerId);
        if ($partner === null || ! $partner->is_active) {
            return false;
        }

        if (! (bool) $partner->is_cmo || ! (bool) $partner->auto_receive_inbound) {
            return false;
        }

        return $this->documentHasShippingObjectEvent($document);
    }

    private function documentHasShippingObjectEvent(EpcisDocument $document): bool
    {
        return EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->where('event_type', 'ObjectEvent')
            ->where(function ($query): void {
                $query
                    ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                    ->orWhere('biz_step', 'shipping');
            })
            ->exists();
    }
}
