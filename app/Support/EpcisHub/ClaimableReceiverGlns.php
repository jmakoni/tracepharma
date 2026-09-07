<?php

declare(strict_types=1);

namespace App\Support\EpcisHub;

use App\Models\Tenant;
use App\Rules\ValidGln;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Tenancy\TenantRunner;

/**
 * Receiver GLNs a tenant may claim for hub inbound (company GLN + org-facility Sites).
 */
final class ClaimableReceiverGlns
{
    /**
     * @return array<string, string> gln => label
     */
    public static function options(Tenant $tenant): array
    {
        $options = [];

        $companyGln = self::normalizeStoredGln($tenant->gln);
        if ($companyGln !== null) {
            $options[$companyGln] = 'Company · '.$companyGln;
        }

        $siteOptions = [];
        try {
            $siteOptions = TenantRunner::run($tenant, function () use ($companyGln): array {
                $labeled = [];
                foreach (EligibleReceiveSites::forOrganization()->get(['name', 'gln']) as $site) {
                    $gln = self::normalizeStoredGln($site->gln);
                    if ($gln === null) {
                        continue;
                    }
                    if ($companyGln !== null && $gln === $companyGln) {
                        continue;
                    }
                    $labeled[$gln] = trim((string) $site->name).' · '.$gln;
                }

                return $labeled;
            });
        } catch (\Throwable) {
            // Admin claim UI / conflict checks may run for tenants whose DB is not provisioned yet.
            $siteOptions = [];
        }

        return $options + $siteOptions;
    }

    /**
     * @return list<string>
     */
    public static function glns(Tenant $tenant): array
    {
        return array_keys(self::options($tenant));
    }

    public static function contains(Tenant $tenant, string $gln): bool
    {
        $normalized = self::normalizeStoredGln($gln);

        return $normalized !== null && array_key_exists($normalized, self::options($tenant));
    }

    /**
     * Prefer GS1 check-digit validation; fall back to 13 digits for legacy stored GLNs.
     */
    public static function normalizeStoredGln(mixed $raw): ?string
    {
        $valid = ValidGln::normalize($raw);
        if ($valid !== null) {
            return $valid;
        }

        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        return strlen($digits) === 13 ? $digits : null;
    }
}
