<?php

namespace App\Actions\Vrs;

use App\Models\Epcis\Epc;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Verification;
use App\Services\Receiving\ReceivingGate;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Custody\TerminalEpcDisposition;

/**
 * Local custody lookup for inbound partner VRS requests (tenant-as-responder).
 * Does not call outbound {@see \App\Services\Vrs\Contracts\VrsClient} — that is for
 * workstation / dispense-check verify against an external router.
 */
final class RespondToInboundVerification
{
    public function __construct(
        private readonly ReceivingGate $receivingGate,
        private readonly ResolveEpcLastKnownGln $lastKnownGln,
    ) {}

    /**
     * @param  array<string, mixed>  $requestPayload
     * @return array{
     *     verification: Verification,
     *     status: string,
     *     message: string,
     *     found: bool
     * }
     */
    public function handle(
        string $gtin14,
        string $serial,
        ?string $lot = null,
        ?string $expiryYymmdd = null,
        array $requestPayload = [],
    ): array {
        $gtin14 = str_pad(preg_replace('/\D+/', '', $gtin14) ?? '', 14, '0', STR_PAD_LEFT);
        $serial = trim($serial);

        $epc = Epc::query()
            ->where('epc_type', 'sgtin')
            ->where('gtin14', $gtin14)
            ->where('serial_number', $serial)
            ->first();

        $found = $epc !== null;

        if ($epc !== null) {
            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                return $this->blockedResponse(
                    epc: $epc,
                    gtin14: $gtin14,
                    serial: $serial,
                    lot: $lot,
                    expiryYymmdd: $expiryYymmdd,
                    requestPayload: $requestPayload,
                    status: 'suspect',
                    message: $this->quarantineBlockMessage($hold),
                    responseExtras: [
                        'blocked_by' => 'open_quarantine_hold',
                        'hold_id' => $hold->getKey(),
                        'reason' => 'quarantined',
                    ],
                    exceptionId: $hold->exception_id !== null ? (int) $hold->exception_id : null,
                );
            }

            $meta = $this->lastKnownGln->latestEventMeta($epc);
            if (TerminalEpcDisposition::matches($meta)) {
                $label = TerminalEpcDisposition::label($meta['disposition'] ?? null);

                return $this->blockedResponse(
                    epc: $epc,
                    gtin14: $gtin14,
                    serial: $serial,
                    lot: $lot,
                    expiryYymmdd: $expiryYymmdd,
                    requestPayload: $requestPayload,
                    status: 'failed',
                    message: 'Serial is recorded as '.$label.' and cannot be verified.',
                    responseExtras: [
                        'blocked_by' => 'terminal_disposition',
                        'disposition' => $meta['disposition'] ?? null,
                        'reason' => 'decommissioned',
                    ],
                );
            }
        }

        $status = $found ? 'verified' : 'failed';
        $message = $found
            ? 'Product verified by TracePharma responder.'
            : 'Serial not found in responder records.';

        $response = [
            'status' => $status,
            'gtin14' => $gtin14,
            'serial' => $serial,
            'lot' => $lot,
            'expiry_yymmdd' => $expiryYymmdd,
            'message' => $message,
            'source' => 'responder',
            'epc_id' => $epc?->getKey(),
        ];

        $verification = Verification::query()->create([
            'gtin14' => $gtin14,
            'serial' => $serial,
            'lot' => $lot,
            'status' => $status,
            'scanned_barcode' => null,
            'verified_by' => null,
            'request_payload' => array_merge($requestPayload, [
                'gtin14' => $gtin14,
                'serial' => $serial,
                'lot' => $lot,
                'expiry_yymmdd' => $expiryYymmdd,
                'source' => 'responder',
            ]),
            'response_payload' => $response,
            'message' => $message,
            'verified_at' => $found ? now() : null,
        ]);

        return [
            'verification' => $verification->refresh(),
            'status' => $status,
            'message' => $message,
            'found' => $found,
        ];
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $responseExtras
     * @return array{
     *     verification: Verification,
     *     status: string,
     *     message: string,
     *     found: bool
     * }
     */
    private function blockedResponse(
        Epc $epc,
        string $gtin14,
        string $serial,
        ?string $lot,
        ?string $expiryYymmdd,
        array $requestPayload,
        string $status,
        string $message,
        array $responseExtras,
        ?int $exceptionId = null,
    ): array {
        $response = array_merge([
            'status' => $status,
            'gtin14' => $gtin14,
            'serial' => $serial,
            'lot' => $lot,
            'expiry_yymmdd' => $expiryYymmdd,
            'message' => $message,
            'source' => 'responder',
            'epc_id' => $epc->getKey(),
        ], $responseExtras);

        $verification = Verification::query()->create([
            'gtin14' => $gtin14,
            'serial' => $serial,
            'lot' => $lot,
            'status' => $status,
            'scanned_barcode' => null,
            'verified_by' => null,
            'request_payload' => array_merge($requestPayload, [
                'gtin14' => $gtin14,
                'serial' => $serial,
                'lot' => $lot,
                'expiry_yymmdd' => $expiryYymmdd,
                'source' => 'responder',
            ]),
            'response_payload' => $response,
            'message' => $message,
            'exception_id' => $exceptionId,
            'verified_at' => null,
        ]);

        return [
            'verification' => $verification->refresh(),
            'status' => $status,
            'message' => $message,
            'found' => true,
        ];
    }

    private function quarantineBlockMessage(QuarantineHold $hold): string
    {
        $caseId = $hold->exception_id;
        $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

        return 'Under quarantine'.$suffix.'. Clear or release quarantine before dispensing.';
    }
}
