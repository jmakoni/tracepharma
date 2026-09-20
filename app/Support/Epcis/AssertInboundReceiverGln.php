<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Actions\Exceptions\RecordInboundReceiverRejected;
use App\Exceptions\InboundReceiverGlnRejected;
use App\Models\Tenant;
use App\Rules\ValidGln;
use App\Support\EpcisHub\ClaimableReceiverGlns;
use App\Support\Gs1\Sgln;
use App\Support\Receiving\EligibleReceiveSites;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Cross-tenant EPCIS reject for paths that already have a tenant.
 *
 * Portal upload, POST /api/v1/epcis/inbound, POST /api/v1/epcis/capture,
 * and per-connection AS2 / HTTPS / tenant SFTP all call
 * {@see self::assertBelongsToCurrentTenant()} after SBDH is readable and
 * before ReceiveEpcisUpload persists events. Hub routing does not call this:
 * the hub already resolved the tenant from Receiver and runs only that tenant.
 *
 * The allowed set is the same one hub claims use: company GLN plus
 * organization receive sites ({@see ClaimableReceiverGlns}).
 */
final class AssertInboundReceiverGln
{
    public static function assertBelongsToCurrentTenant(string $content): void
    {
        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new InboundReceiverGlnRejected(
                'Inbound EPCIS cannot be accepted without an initialized tenant.',
            );
        }

        $raw = self::rawReceiverIdentifier($content);
        $receiver = self::comparableGln($raw);
        if ($receiver !== null && self::belongsToCurrentTenant($tenant, $receiver)) {
            return;
        }

        $stated = $receiver ?? ($raw !== null && $raw !== '' ? $raw : null);
        $message = $stated === null
            ? 'SBDH Receiver GLN is missing; inbound EPCIS was rejected for this tenant.'
            : "SBDH Receiver GLN [{$stated}] does not belong to this tenant.";

        app(RecordInboundReceiverRejected::class)->handle($message);

        Log::warning($message, [
            'tenant_id' => (string) $tenant->getTenantKey(),
            'receiver_gln' => $receiver,
        ]);

        throw new InboundReceiverGlnRejected($message);
    }

    private static function belongsToCurrentTenant(Tenant $tenant, string $receiverGln): bool
    {
        $normalized = ValidGln::normalize($receiverGln);
        if ($normalized === null) {
            return false;
        }

        $company = ValidGln::normalize($tenant->gln);
        if ($company !== null && hash_equals($company, $normalized)) {
            return true;
        }

        // Same organization receive sites ClaimableReceiverGlns uses, queried
        // in the current tenant so tenancy is not ended.
        foreach (EligibleReceiveSites::forOrganization()->pluck('gln') as $siteGln) {
            $site = ValidGln::normalize($siteGln);
            if ($site !== null && hash_equals($site, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Party GLN from an SBDH identifier. An SGLN counts only when the
     * extension is 0. A sub-location extension is not the party GLN.
     * Digit GLNs are accepted without a check-digit gate so hub routing
     * still matches tenant rows that store a 13-digit GLN as-is.
     */
    public static function partyGlnFromIdentifier(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $sgln = self::sgln($value);
        if ($sgln !== null) {
            if ($sgln['extension'] !== '0') {
                return null;
            }

            return Sgln::normalizeGln($sgln['gln']) ?? $sgln['gln'];
        }

        if (preg_match('#(?:^|/)414/(\d{13})(?:/|$)#', $value, $matches) === 1) {
            return Sgln::normalizeGln($matches[1]);
        }

        if (str_contains($value, ':') || str_contains($value, '/')) {
            return null;
        }

        return Sgln::normalizeGln($value);
    }

    public static function identifierIsNonZeroSglnExtension(?string $raw): bool
    {
        if ($raw === null) {
            return false;
        }

        $sgln = self::sgln(trim($raw));

        return $sgln !== null && $sgln['extension'] !== '0';
    }

    /**
     * 13-digit GLN via {@see ValidGln}. An SGLN is that GLN only when the
     * extension is 0. A raw URN is never stripped down to digits.
     */
    private static function comparableGln(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $sgln = self::sgln($value);
        if ($sgln !== null) {
            if ($sgln['extension'] !== '0') {
                return null;
            }

            return ValidGln::normalize($sgln['gln']);
        }

        if (preg_match('#(?:^|/)414/(\d{13})(?:/|$)#', $value, $matches) === 1) {
            return ValidGln::normalize($matches[1]);
        }

        if (str_contains($value, ':') || str_contains($value, '/')) {
            return null;
        }

        return ValidGln::normalize($value);
    }

    /**
     * @return array{gln: string, extension: string}|null
     */
    private static function sgln(string $value): ?array
    {
        $uri = $value;
        if (preg_match('/^\d+\.\d+\.\d+$/', $value) === 1) {
            $uri = 'urn:epc:id:sgln:'.$value;
        } elseif (preg_match('/urn:epc:id:sgln:\d+\.\d+\.\d+/i', $value, $matches) === 1) {
            $uri = $matches[0];
        }

        if (! str_starts_with(strtolower($uri), 'urn:epc:id:sgln:')) {
            return null;
        }

        $parsed = Sgln::fromUrn($uri);
        if ($parsed === null) {
            return null;
        }

        return [
            'gln' => $parsed['gln'],
            'extension' => $parsed['extension'],
        ];
    }

    private static function rawReceiverIdentifier(string $content): ?string
    {
        $trimmed = ltrim($content);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return self::receiverFromJson($trimmed);
        }

        $identifier = app(SbdhHeaderExtractor::class)->extract($content)['receiver_identifier'];

        return is_string($identifier) && trim($identifier) !== '' ? trim($identifier) : null;
    }

    private static function receiverFromJson(string $content): ?string
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $sbdh = $decoded['epcisHeader']['standardBusinessDocumentHeader']
            ?? $decoded['EPCISHeader']['StandardBusinessDocumentHeader']
            ?? null;

        if (! is_array($sbdh)) {
            return null;
        }

        return self::jsonPartyIdentifier($sbdh['receiver'] ?? $sbdh['Receiver'] ?? null);
    }

    private static function jsonPartyIdentifier(mixed $party): ?string
    {
        if (is_string($party) && trim($party) !== '') {
            return trim($party);
        }

        if (! is_array($party)) {
            return null;
        }

        $identifier = $party['identifier'] ?? $party['Identifier'] ?? null;
        if (is_string($identifier) && trim($identifier) !== '') {
            return trim($identifier);
        }

        if (is_array($identifier)) {
            if (isset($identifier['value']) && is_string($identifier['value']) && trim($identifier['value']) !== '') {
                return trim($identifier['value']);
            }
            if (isset($identifier[0]) && is_string($identifier[0]) && trim($identifier[0]) !== '') {
                return trim($identifier[0]);
            }
        }

        return null;
    }
}
