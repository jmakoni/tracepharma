<?php

namespace App\Actions\Shipping;

use App\Exceptions\WmsIdempotencyConflictException;
use App\Models\Shipping\OutboundShippingSession;
use App\Support\Epcis\EpcisCacheLock;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;

/**
 * WMS ship-confirm bridge: open a ship order, confirm scans, optionally set party/refs,
 * and complete when send validation passes.
 */
final class ProcessWmsShipConfirm
{
    public function __construct(
        private readonly OpenOutboundShippingSession $openSession,
        private readonly ConfirmOutboundShippingScan $confirmScan,
        private readonly UpdateOutboundShippingReferences $updateReferences,
        private readonly UpdateOutboundShippingParty $updateParty,
        private readonly ValidateOutboundShippingSend $validateSend,
        private readonly CompleteOutboundShippingSession $completeSession,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     http_status: int,
     *     status: string,
     *     session_id: int,
     *     confirmed_count: int,
     *     message: string,
     *     blockers?: list<string>,
     *     scan_errors?: list<array{scan: string, message: string, effect: string}>,
     *     idempotent_replay?: bool
     * }
     */
    public function handle(array $payload, ?string $idempotencyKey = null): array
    {
        if (! TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()) {
            throw new DomainException('Outbound shipping is not available for this tenant profile.');
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $complete = (bool) ($payload['complete'] ?? true);

        if ($idempotencyKey !== null) {
            return $this->handleWithIdempotency($payload, $idempotencyKey, $complete);
        }

        return $this->processNewSession($payload, $complete, null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     http_status: int,
     *     status: string,
     *     session_id: int,
     *     confirmed_count: int,
     *     message: string,
     *     blockers?: list<string>,
     *     scan_errors?: list<array{scan: string, message: string, effect: string}>,
     *     idempotent_replay?: bool
     * }
     */
    private function handleWithIdempotency(array $payload, string $idempotencyKey, bool $complete): array
    {
        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');
        $lockKey = 'wms-ship-confirm:'.$tenantId.':'.$idempotencyKey;

        return EpcisCacheLock::lock($lockKey, 120)->block(10, function () use ($payload, $idempotencyKey, $complete): array {
            $existing = OutboundShippingSession::query()
                ->where('wms_idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertPayloadMatchesSession($payload, $existing);

                $missingScans = $this->missingScansForSession($payload, $existing);

                if ($missingScans === []) {
                    return $this->buildResponseFromSession(
                        $existing,
                        $this->storedComplete($existing, $complete),
                        idempotentReplay: true,
                    );
                }

                return $this->continueExistingSession(
                    $existing,
                    $payload,
                    $missingScans,
                    $complete,
                    idempotentReplay: true,
                );
            }

            return $this->processNewSession($payload, $complete, $idempotencyKey);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     http_status: int,
     *     status: string,
     *     session_id: int,
     *     confirmed_count: int,
     *     message: string,
     *     blockers?: list<string>,
     *     scan_errors?: list<array{scan: string, message: string, effect: string}>,
     *     idempotent_replay?: bool
     * }
     */
    private function processNewSession(array $payload, bool $complete, ?string $idempotencyKey): array
    {
        $scans = $this->normalizeScans($payload);

        if ($scans === []) {
            throw new DomainException('At least one scan is required.');
        }

        $siteId = isset($payload['site_id']) && $payload['site_id'] !== null && $payload['site_id'] !== ''
            ? (int) $payload['site_id']
            : null;

        $expectedCount = $this->payloadExpectedCount($payload);

        $session = $this->openSession->handle(
            $siteId,
            expectedCount: $expectedCount,
        );

        if ($idempotencyKey !== null) {
            try {
                $session->forceFill([
                    'wms_idempotency_key' => $idempotencyKey,
                    'wms_complete' => $complete,
                ])->save();
                $session = $session->refresh();
            } catch (UniqueConstraintViolationException|QueryException $e) {
                if (! $this->isWmsIdempotencyKeyDuplicate($e)) {
                    throw $e;
                }

                $existing = OutboundShippingSession::query()
                    ->where('wms_idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing === null) {
                    throw $e;
                }

                $this->assertPayloadMatchesSession($payload, $existing);

                $missingScans = $this->missingScansForSession($payload, $existing);

                if ($missingScans === []) {
                    return $this->buildResponseFromSession(
                        $existing,
                        $this->storedComplete($existing, $complete),
                        idempotentReplay: true,
                    );
                }

                return $this->continueExistingSession(
                    $existing,
                    $payload,
                    $missingScans,
                    $complete,
                    idempotentReplay: true,
                );
            }
        }

        $scanErrors = [];

        foreach ($scans as $scan) {
            $result = $this->confirmScan->handle($session, $scan);

            if (! $result['ok'] && $result['effect'] !== 'already_confirmed') {
                $scanErrors[] = [
                    'scan' => $scan,
                    'message' => $result['message'],
                    'effect' => $result['effect'],
                ];
            }
        }

        return $this->finalizeSessionAfterScans($session, $payload, $complete, $scanErrors);
    }

    /**
     * Resume an idempotent session: retry only scans not yet confirmed on the session.
     *
     * Idempotency keys are stored before scan confirmation so WMS can safely replay
     * after partial scan_errors; replays accept payload scans that are a superset of
     * confirmed session lines and only process the difference.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $scansToConfirm
     * @return array{
     *     http_status: int,
     *     status: string,
     *     session_id: int,
     *     confirmed_count: int,
     *     message: string,
     *     blockers?: list<string>,
     *     scan_errors?: list<array{scan: string, message: string, effect: string}>,
     *     idempotent_replay?: bool
     * }
     */
    private function continueExistingSession(
        OutboundShippingSession $session,
        array $payload,
        array $scansToConfirm,
        bool $complete,
        bool $idempotentReplay = false,
    ): array {
        $scanErrors = [];

        foreach ($scansToConfirm as $scan) {
            $result = $this->confirmScan->handle($session, $scan);

            if (! $result['ok'] && $result['effect'] !== 'already_confirmed') {
                $scanErrors[] = [
                    'scan' => $scan,
                    'message' => $result['message'],
                    'effect' => $result['effect'],
                ];
            }
        }

        $response = $this->finalizeSessionAfterScans($session, $payload, $complete, $scanErrors);

        if ($idempotentReplay) {
            $response['idempotent_replay'] = true;
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{scan: string, message: string, effect: string}>  $scanErrors
     * @return array{
     *     http_status: int,
     *     status: string,
     *     session_id: int,
     *     confirmed_count: int,
     *     message: string,
     *     blockers?: list<string>,
     *     scan_errors?: list<array{scan: string, message: string, effect: string}>,
     *     idempotent_replay?: bool
     * }
     */
    private function finalizeSessionAfterScans(
        OutboundShippingSession $session,
        array $payload,
        bool $complete,
        array $scanErrors,
    ): array {
        $session = $session->refresh();

        if ($scanErrors !== []) {
            return [
                'http_status' => 422,
                'status' => 'scan_errors',
                'session_id' => (int) $session->getKey(),
                'confirmed_count' => (int) $session->confirmed_count,
                'message' => 'One or more scans could not be confirmed.',
                'scan_errors' => $scanErrors,
            ];
        }

        if ($this->hasPartyPayload($payload)) {
            try {
                $session = $this->updateParty->handle($session, $this->partyPayload($payload));
            } catch (DomainException $e) {
                return $this->scannedResponse($session, [$e->getMessage()]);
            }
        }

        if ($this->hasReferencePayload($payload)) {
            $session = $this->updateReferences->handle($session, $this->referencePayload($payload));
        }

        $blockers = $this->validateSend->handle($session->refresh());

        if ($blockers !== []) {
            return $this->scannedResponse($session, $blockers);
        }

        if (! $complete) {
            return $this->scannedResponse($session, []);
        }

        $session = $this->completeSession->handle($session);

        return [
            'http_status' => 200,
            'status' => 'completed',
            'session_id' => (int) $session->getKey(),
            'confirmed_count' => (int) $session->confirmed_count,
            'message' => 'Shipment sent.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function missingScansForSession(array $payload, OutboundShippingSession $session): array
    {
        $payloadScans = $this->normalizeScans($payload);
        $sessionScans = $this->sessionScans($session);

        return array_values(array_diff($payloadScans, $sessionScans));
    }

    /**
     * @return list<string>
     */
    private function sessionScans(OutboundShippingSession $session): array
    {
        $session->loadMissing('scanLines');

        return $session->scanLines
            ->sortBy('id')
            ->pluck('scan_raw')
            ->map(fn (mixed $scan): string => trim((string) $scan))
            ->filter(fn (string $scan): bool => $scan !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayloadMatchesSession(array $payload, OutboundShippingSession $session): void
    {
        $requestedComplete = (bool) ($payload['complete'] ?? true);

        if ($session->wms_complete !== null && $requestedComplete !== (bool) $session->wms_complete) {
            throw new WmsIdempotencyConflictException(
                'Idempotency key replay rejected: complete differs from the original request.',
            );
        }

        $payloadScans = $this->normalizeScans($payload);
        $sessionScans = $this->sessionScans($session);

        if ($sessionScans !== [] && array_diff($sessionScans, $payloadScans) !== []) {
            throw new WmsIdempotencyConflictException(
                'Idempotency key replay rejected: scans differ from the original request.',
            );
        }

        if ($this->hasPartyPayload($payload)) {
            $party = $this->partyPayload($payload);

            foreach (['trading_partner_id', 'ship_to_site_id', 'ship_to_gln', 'outbound_connection_id'] as $key) {
                if (! array_key_exists($key, $party)) {
                    continue;
                }

                if (! $this->partyFieldMatches($key, $party[$key], $session->getAttribute($key))) {
                    throw new WmsIdempotencyConflictException(
                        'Idempotency key replay rejected: party or connection differs from the original request.',
                    );
                }
            }
        }

        if ($this->hasReferencePayload($payload)) {
            $references = $this->referencePayload($payload);

            foreach (['asn_number', 'customer_po', 'invoice_number', 'expected_count'] as $key) {
                if (! array_key_exists($key, $references)) {
                    continue;
                }

                if (! $this->referenceFieldMatches($key, $references[$key], $session->getAttribute($key))) {
                    throw new WmsIdempotencyConflictException(
                        'Idempotency key replay rejected: shipment references differ from the original request.',
                    );
                }
            }
        }
    }

    private function storedComplete(OutboundShippingSession $session, bool $requestedComplete): bool
    {
        return $session->wms_complete !== null
            ? (bool) $session->wms_complete
            : $requestedComplete;
    }

    private function isWmsIdempotencyKeyDuplicate(QueryException|UniqueConstraintViolationException $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    private function referenceFieldMatches(string $key, mixed $expected, mixed $actual): bool
    {
        if ($key === 'expected_count') {
            return (int) $expected === (int) $actual;
        }

        $expectedValue = blank($expected) ? null : trim((string) $expected);
        $actualValue = blank($actual) ? null : trim((string) $actual);

        return $expectedValue === $actualValue;
    }

    private function partyFieldMatches(string $key, mixed $expected, mixed $actual): bool
    {
        if (in_array($key, ['trading_partner_id', 'ship_to_site_id', 'outbound_connection_id'], true)) {
            $expectedId = $expected !== null && $expected !== '' ? (int) $expected : null;
            $actualId = $actual !== null && $actual !== '' ? (int) $actual : null;

            return $expectedId === $actualId;
        }

        if ($key === 'ship_to_gln') {
            $expectedGln = blank($expected) ? null : trim((string) $expected);
            $actualGln = blank($actual) ? null : trim((string) $actual);

            return $expectedGln === $actualGln;
        }

        return $expected === $actual;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function normalizeScans(array $payload): array
    {
        return array_values(array_filter(
            array_map(
                fn (mixed $scan): string => trim((string) $scan),
                (array) ($payload['scans'] ?? []),
            ),
            fn (string $scan): bool => $scan !== '',
        ));
    }

    /**
     * @param  list<string>  $blockers
     * @return array{
     *     http_status: int,
     *     status: string,
     *     session_id: int,
     *     confirmed_count: int,
     *     message: string,
     *     blockers: list<string>
     * }
     */
    private function scannedResponse(OutboundShippingSession $session, array $blockers): array
    {
        return [
            'http_status' => $blockers === [] ? 200 : 422,
            'status' => 'scanned',
            'session_id' => (int) $session->getKey(),
            'confirmed_count' => (int) $session->confirmed_count,
            'message' => $blockers === []
                ? 'Scans confirmed; shipment not completed.'
                : 'Scans confirmed; complete Ship Order in UI for customer/send.',
            'blockers' => $blockers,
        ];
    }

    private function normalizeIdempotencyKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    /**
     * @return array{
     *     http_status: int,
     *     status: string,
     *     session_id: int,
     *     confirmed_count: int,
     *     message: string,
     *     blockers?: list<string>,
     *     idempotent_replay?: bool
     * }
     */
    private function buildResponseFromSession(
        OutboundShippingSession $session,
        bool $complete,
        bool $idempotentReplay = false,
    ): array {
        if ($session->status === 'completed' && $session->shipping_events_generated_at !== null) {
            return [
                'http_status' => 200,
                'status' => 'completed',
                'session_id' => (int) $session->getKey(),
                'confirmed_count' => (int) $session->confirmed_count,
                'message' => 'Shipment sent.',
                'idempotent_replay' => $idempotentReplay,
            ];
        }

        $blockers = $this->validateSend->handle($session->refresh());

        if ($blockers !== [] || ! $complete) {
            $response = $this->scannedResponse($session, $blockers);
            if ($idempotentReplay) {
                $response['idempotent_replay'] = true;
            }

            return $response;
        }

        $session = $this->completeSession->handle($session);

        return [
            'http_status' => 200,
            'status' => 'completed',
            'session_id' => (int) $session->getKey(),
            'confirmed_count' => (int) $session->confirmed_count,
            'message' => 'Shipment sent.',
            'idempotent_replay' => $idempotentReplay,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasPartyPayload(array $payload): bool
    {
        return Arr::hasAny($payload, [
            'trading_partner_id',
            'customer_id',
            'ship_to_site_id',
            'ship_to_gln',
            'outbound_connection_id',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function partyPayload(array $payload): array
    {
        $data = [];

        if (Arr::has($payload, 'trading_partner_id')) {
            $data['trading_partner_id'] = $payload['trading_partner_id'];
        } elseif (Arr::has($payload, 'customer_id')) {
            $data['trading_partner_id'] = $payload['customer_id'];
        }

        foreach (['ship_to_site_id', 'ship_to_gln', 'outbound_connection_id'] as $key) {
            if (Arr::has($payload, $key)) {
                $data[$key] = $payload[$key];
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasReferencePayload(array $payload): bool
    {
        return Arr::hasAny($payload, [
            'asn_number',
            'asn',
            'customer_po',
            'po',
            'invoice_number',
            'shipment_reference',
            'dscsa_affirm',
            'expected_count',
            'quantity',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function referencePayload(array $payload): array
    {
        $data = [];

        if (Arr::has($payload, 'asn_number')) {
            $data['asn_number'] = $payload['asn_number'];
        } elseif (Arr::has($payload, 'asn')) {
            $data['asn_number'] = $payload['asn'];
        }

        if (Arr::has($payload, 'customer_po')) {
            $data['customer_po'] = $payload['customer_po'];
        } elseif (Arr::has($payload, 'po')) {
            $data['customer_po'] = $payload['po'];
        }

        foreach (['invoice_number', 'shipment_reference', 'dscsa_affirm'] as $key) {
            if (Arr::has($payload, $key)) {
                $data[$key] = $payload[$key];
            }
        }

        $expected = $this->payloadExpectedCount($payload);
        if ($expected !== null) {
            $data['expected_count'] = $expected;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadExpectedCount(array $payload): ?int
    {
        if (Arr::has($payload, 'expected_count') && $payload['expected_count'] !== null && $payload['expected_count'] !== '') {
            return max(0, (int) $payload['expected_count']);
        }

        if (Arr::has($payload, 'quantity') && $payload['quantity'] !== null && $payload['quantity'] !== '') {
            return max(0, (int) $payload['quantity']);
        }

        return null;
    }
}
