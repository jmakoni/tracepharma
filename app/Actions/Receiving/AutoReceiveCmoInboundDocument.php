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
use App\Support\Receiving\ResolveInboundAggregationChildEpcs;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
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
        private readonly ResolveInboundAggregationChildEpcs $resolveInboundAggregationChildEpcs,
    ) {}

    public function handle(EpcisDocument $document): ?ReceivingSession
    {
        if (! $this->shouldAutoReceive($document)) {
            return null;
        }

        $session = null;

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

                $childIds = $this->resolveInboundAggregationChildEpcs->childEpcIdsForParent(
                    $session,
                    $line->epc,
                    (int) $document->getKey(),
                );

                $confirm = $this->confirmReceivingScan->handle(
                    $session->fresh() ?? $session,
                    $uri,
                    userId: null,
                    autoConfirmChildren: $childIds !== [],
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
            $this->abandonFailedAutoReceiveSession($session, $document, $e);

            return null;
        }
    }

    /**
     * Cancel a leftover open/in-progress auto-receive session so shouldAutoReceive
     * can retry and EPCs are not stuck on a dead session.
     */
    private function abandonFailedAutoReceiveSession(
        ?ReceivingSession $session,
        EpcisDocument $document,
        Throwable $error,
    ): void {
        Log::warning('receiving.auto_cmo_receive_failed', [
            'source' => 'auto_cmo_receive',
            'document_id' => (int) $document->getKey(),
            'session_id' => $session !== null ? (int) $session->getKey() : null,
            'error' => $error->getMessage(),
        ]);

        if ($session === null) {
            $document->unsetRelation('receivingSession');
            $session = $document->openReceivingSession();
        }

        if ($session === null) {
            return;
        }

        try {
            DB::transaction(function () use ($session): void {
                $locked = ReceivingSession::query()
                    ->whereKey($session->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    return;
                }

                // Re-check under lock so a peer CompleteReceivingSession cannot leave
                // authored receiving EPCIS on a session we then mark cancelled.
                if ($locked->receiving_events_generated_at !== null
                    || $locked->receiving_epcis_document_id !== null) {
                    return;
                }

                if (! in_array($locked->status, ['open', 'in_progress'], true)) {
                    return;
                }

                $locked->forceFill([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                ])->save();
            });
        } catch (Throwable $cancelError) {
            Log::warning('receiving.auto_cmo_receive_abandon_failed', [
                'source' => 'auto_cmo_receive',
                'document_id' => (int) $document->getKey(),
                'session_id' => (int) $session->getKey(),
                'error' => $cancelError->getMessage(),
            ]);
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
