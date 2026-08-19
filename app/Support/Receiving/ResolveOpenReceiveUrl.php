<?php

namespace App\Support\Receiving;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Resolve an "Open receive" deep-link for Asset Tracking (and similar).
 *
 * Mirrors Operations Hub receive routing for:
 * 1) EPC already on an open session's scan lines
 * 2) In-transit transfer → open/resume transfer receive
 * 3) Unique ASN match without an open ASN session → open ASN
 *
 * Does not use the Ops Hub "exactly one open session" fallback.
 */
final class ResolveOpenReceiveUrl
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly ResolveReceiveScanContext $resolveReceiveScanContext,
        private readonly OpenTransferReceivingSession $openTransferReceivingSession,
        private readonly OpenReceivingSessionFromDocument $openReceivingSessionFromDocument,
    ) {}

    /**
     * Whether Open receive should be offered (does not open sessions).
     */
    public function hasContext(string $barcode): bool
    {
        return $this->peekSessionId($barcode) !== null
            || $this->peekOpenableContext($barcode);
    }

    /**
     * Safe deep-link when a receive session already exists (never opens/creates sessions).
     * Use for table/HUD hrefs; Asset Tracking click still uses {@see handle()}.
     */
    public function previewUrl(string $barcode, ?int $userId = null): ?string
    {
        $normalized = ElementString::normalize(trim($barcode));

        if ($normalized === '') {
            return null;
        }

        $resolved = $this->resolveEpcFromScan->handle($normalized);
        $epc = $resolved['epc'];

        $onSessionUrl = $this->openReceivingSessionUrlForEpcOnScanLines($epc, $normalized, $userId);
        if ($onSessionUrl !== null) {
            return $onSessionUrl;
        }

        if ($epc === null) {
            return null;
        }

        try {
            $context = $this->resolveReceiveScanContext->handle($normalized);

            if (! empty($context['in_transit_transferring_session'])) {
                $existing = ReceivingSession::query()
                    ->where('transferring_session_id', $context['in_transit_transferring_session']->getKey())
                    ->whereIn('status', ['open', 'in_progress'])
                    ->first();

                if ($existing !== null && $this->userCanViewReceivingSession($existing, $userId)) {
                    return ReceiveLayout::sessionUrl($existing, ['scan' => $normalized]);
                }
            }

            if (! empty($context['matched_inbound_document'])) {
                $existingAsn = ReceivingSession::query()
                    ->where('epcis_document_id', $context['matched_inbound_document']->getKey())
                    ->whereIn('status', ['open', 'in_progress'])
                    ->orderByDesc('opened_at')
                    ->first();

                if ($existingAsn !== null && $this->userCanViewReceivingSession($existingAsn, $userId)) {
                    return ReceiveLayout::sessionUrl($existingAsn, ['scan' => $normalized]);
                }
            }
        } catch (InvalidArgumentException|DomainException) {
            return null;
        }

        return null;
    }

    /**
     * Open/resume the target receiving session when needed and return its view URL with scan=.
     */
    public function handle(string $barcode, ?int $userId = null): ?string
    {
        $normalized = ElementString::normalize(trim($barcode));

        if ($normalized === '') {
            return null;
        }

        $resolved = $this->resolveEpcFromScan->handle($normalized);
        $epc = $resolved['epc'];

        $onSessionUrl = $this->openReceivingSessionUrlForEpcOnScanLines($epc, $normalized, $userId);
        if ($onSessionUrl !== null) {
            return $onSessionUrl;
        }

        if ($epc === null) {
            return null;
        }

        try {
            $context = $this->resolveReceiveScanContext->handle($normalized);

            if (! empty($context['in_transit_transferring_session'])) {
                try {
                    $receiving = $this->openTransferReceivingSession->handle(
                        $context['in_transit_transferring_session'],
                        $userId,
                    );
                } catch (AuthorizationException) {
                    return null;
                }

                if (! $this->userCanViewReceivingSession($receiving, $userId)) {
                    return null;
                }

                return ReceiveLayout::sessionUrl($receiving, ['scan' => $normalized]);
            }

            if (! empty($context['matched_inbound_document'])) {
                $document = $context['matched_inbound_document'];
                if (! $document instanceof EpcisDocument) {
                    return null;
                }

                if (! $this->userCanOpenAsnFromDocument($document, $userId)) {
                    return null;
                }

                try {
                    $asnSession = $this->openReceivingSessionFromDocument->handle(
                        $document,
                        $this->resolveAsnOpenSiteId($document, $userId),
                        $userId,
                    );

                    return ReceiveLayout::sessionUrl($asnSession, ['scan' => $normalized]);
                } catch (InvalidArgumentException|DomainException|AuthorizationException) {
                    return null;
                }
            }
        } catch (InvalidArgumentException|DomainException|AuthorizationException) {
            return null;
        }

        return null;
    }

    private function peekSessionId(string $barcode): ?int
    {
        $normalized = ElementString::normalize(trim($barcode));

        if ($normalized === '') {
            return null;
        }

        $epc = $this->resolveEpcFromScan->handle($normalized)['epc'];

        if ($epc === null) {
            return null;
        }

        $session = ReceivingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('scanLines', fn ($query) => $query->where('epc_id', $epc->getKey()))
            ->orderByDesc('opened_at')
            ->first();

        if ($session === null || ! $this->userCanViewReceivingSession($session)) {
            return null;
        }

        return (int) $session->getKey();
    }

    private function peekOpenableContext(string $barcode): bool
    {
        $normalized = ElementString::normalize(trim($barcode));

        if ($normalized === '') {
            return false;
        }

        try {
            $context = $this->resolveReceiveScanContext->handle($normalized);

            if (! empty($context['in_transit_transferring_session'])) {
                if (! $this->userCanOpenReceive()) {
                    return false;
                }

                $transfer = $context['in_transit_transferring_session'];
                $user = $this->resolveUser();

                return ! ($user instanceof User)
                    || SiteAccess::canAccessSite($user, (int) $transfer->to_site_id);
            }

            if (! empty($context['matched_inbound_document'])) {
                $document = $context['matched_inbound_document'];

                return $document instanceof EpcisDocument
                    && $this->userCanOpenAsnFromDocument($document);
            }

            return false;
        } catch (InvalidArgumentException|DomainException) {
            return false;
        }
    }

    private function openReceivingSessionUrlForEpcOnScanLines(?Epc $epc, string $normalized, ?int $userId = null): ?string
    {
        if ($epc === null) {
            return null;
        }

        $session = ReceivingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('scanLines', fn ($query) => $query->where('epc_id', $epc->getKey()))
            ->orderByDesc('opened_at')
            ->first();

        if ($session === null || ! $this->userCanViewReceivingSession($session, $userId)) {
            return null;
        }

        return ReceiveLayout::sessionUrl($session, ['scan' => $normalized]);
    }

    /**
     * Prefer ASN ship-to over CurrentSite when opening via deep link.
     *
     * Returns null so {@see ResolveReceivingSite} prefers document ship-to when accessible;
     * otherwise falls back to CurrentSite (no ship-to, or ship-to matches current selection).
     */
    private function resolveAsnOpenSiteId(EpcisDocument $document, ?int $userId = null): ?int
    {
        $user = $this->resolveUser($userId);
        $shipToSiteId = $document->ship_to_site_id !== null ? (int) $document->ship_to_site_id : null;
        $currentSiteId = CurrentSite::id();

        if ($shipToSiteId === null) {
            return $currentSiteId;
        }

        if ($user instanceof User && SiteAccess::canAccessSite($user, $shipToSiteId)) {
            return null;
        }

        if ($currentSiteId !== null && $currentSiteId === $shipToSiteId) {
            return $currentSiteId;
        }

        return $currentSiteId;
    }

    private function userCanOpenReceive(?int $userId = null): bool
    {
        $user = $this->resolveUser($userId);

        if (! $user instanceof User) {
            return false;
        }

        return JobRoleAccess::allows(Permissions::NavReceive, $user);
    }

    private function userCanOpenAsnFromDocument(EpcisDocument $document, ?int $userId = null): bool
    {
        if (! $this->userCanOpenReceive($userId)) {
            return false;
        }

        $user = $this->resolveUser($userId);

        if (! $user instanceof User) {
            return false;
        }

        $shipToSiteId = $document->ship_to_site_id !== null ? (int) $document->ship_to_site_id : null;

        if ($shipToSiteId !== null) {
            return SiteAccess::canAccessSite($user, $shipToSiteId);
        }

        $currentSiteId = CurrentSite::id();
        if ($currentSiteId !== null && ! SiteAccess::canAccessSite($user, $currentSiteId)) {
            return false;
        }

        return true;
    }

    private function userCanViewReceivingSession(ReceivingSession $session, ?int $userId = null): bool
    {
        $user = $this->resolveUser($userId);

        if (! $user instanceof User) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $session);
    }

    private function resolveUser(?int $userId = null): ?User
    {
        if ($userId !== null) {
            $user = User::query()->find($userId);

            return $user instanceof User ? $user : null;
        }

        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
