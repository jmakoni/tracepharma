<?php

declare(strict_types=1);

namespace App\Support\Vrs;

/**
 * GS1 Lightweight Messaging ↔ TracePharma flat VRS mapping.
 * EPCIS capture still rejects LMS via RejectGs1VrsAsEpcis.
 */
final class Gs1LmsEnvelope
{
    /**
     * @return array{verificationRequest: array<string, string>}
     */
    public static function buildVerificationRequest(
        string $gtin14,
        string $serial,
        ?string $lot,
        ?string $expiryYymmdd,
        ?string $requestorGln,
    ): array {
        $inner = array_filter([
            'gtin' => $gtin14,
            'serialNumber' => $serial,
            'lotNumber' => $lot,
            'expiryDate' => self::expiryYymmddToIso($expiryYymmdd),
            'requestorGln' => $requestorGln,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return ['verificationRequest' => $inner];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizeInboundRequest(array $payload): array
    {
        foreach (['verificationRequest', 'GS1-Verification-Request'] as $key) {
            $inner = $payload[$key] ?? null;
            if (! is_array($inner)) {
                continue;
            }

            $gtin = $inner['gtin'] ?? $inner['gtin14'] ?? null;

            return [
                'gtin14' => $gtin,
                'gtin' => $gtin,
                'serial' => $inner['serialNumber'] ?? $inner['serial'] ?? null,
                'lot' => $inner['lotNumber'] ?? $inner['lot'] ?? null,
                'expiry' => $inner['expiryDate'] ?? $inner['expiry'] ?? null,
                'expiry_yymmdd' => $inner['expiry_yymmdd'] ?? null,
            ];
        }

        return $payload;
    }

    public static function unwrapResponse(mixed $body): mixed
    {
        if (! is_array($body)) {
            return $body;
        }

        foreach (['verificationResponse', 'GS1-Verification-Response'] as $key) {
            $inner = $body[$key] ?? null;
            if (! is_array($inner)) {
                continue;
            }

            $status = $inner['status'] ?? $inner['verificationResult'] ?? null;

            return [
                'verified' => $inner['verified'] ?? ($status === 'verified'),
                'status' => $status,
                'reason_code' => $inner['reasonCode'] ?? $inner['reason_code'] ?? null,
                'message' => $inner['message'] ?? $inner['verificationMessage'] ?? null,
            ];
        }

        return $body;
    }

    public static function expiryYymmddToIso(?string $yymmdd): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $yymmdd) ?? '';
        if (strlen($digits) !== 6) {
            return null;
        }

        return '20'.substr($digits, 0, 2).'-'.substr($digits, 2, 2).'-'.substr($digits, 4, 2);
    }
}
