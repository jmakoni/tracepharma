<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use DomainException;

/**
 * Build EPCIS object keys for storage disks.
 *
 * Local tenant disks keep short paths: epcis/{inbound|outbound}/...
 *
 * S3 (hub IAM inbound/*):
 *   new inbound uploads, tenancy on or tenant id passed → inbound/{tenantId}/{filename}
 *   new inbound uploads with tenancy off and no tenant id → inbound/{filename}
 *   already-stored hub, tenant-prefixed, or legacy tenants/{id}/epcis/... keys → returned as-is
 *   outbound on S3 → rejected (authored payloads use the local disk)
 */
final class EpcisStoragePath
{
    /**
     * @param  string  $relativePath  Path under epcis/…, hub inbound/…, or legacy tenants/{id}/epcis/…
     */
    public static function onDisk(string $disk, string $relativePath, ?string $tenantId = null): string
    {
        $relative = ltrim(str_replace('\\', '/', $relativePath), '/');

        if (! self::diskNeedsS3HubLayout($disk)) {
            return self::normalizeRelative($relative);
        }

        // Already a stored S3 object key — do not rewrite (reads / requeue / legacy).
        if (self::isStoredS3Key($relative)) {
            return $relative;
        }

        $normalized = self::normalizeRelative($relative);

        if (str_starts_with($normalized, 'epcis/inbound/')) {
            $file = substr($normalized, strlen('epcis/inbound/'));
            if ($file === '' || str_contains($file, '/')) {
                throw new DomainException('Hub inbound S3 key must be inbound/{filename}.');
            }

            $resolvedTenantId = self::resolvedTenantId($tenantId);
            if ($resolvedTenantId !== null) {
                return 'inbound/'.$resolvedTenantId.'/'.$file;
            }

            return 'inbound/'.$file;
        }

        if (str_starts_with($normalized, 'epcis/outbound/')) {
            throw new DomainException(
                'Outbound EPCIS payloads must use the local disk; S3 hub layout is inbound-only.',
            );
        }

        throw new DomainException('Unsupported S3 EPCIS path: '.$normalized);
    }

    public static function diskNeedsS3HubLayout(string $disk): bool
    {
        return (string) config("filesystems.disks.{$disk}.driver") === 's3';
    }

    /**
     * @deprecated Use diskNeedsS3HubLayout(); kept for callers/tests that still reference the old name.
     */
    public static function diskNeedsTenantPrefix(string $disk): bool
    {
        return self::diskNeedsS3HubLayout($disk);
    }

    private static function isStoredS3Key(string $path): bool
    {
        if (preg_match('#^inbound/[^/]+$#', $path) === 1) {
            return true;
        }

        if (preg_match('#^inbound/[^/]+/[^/]+$#', $path) === 1) {
            return true;
        }

        return preg_match('#^tenants/[^/]+/epcis/.+#', $path) === 1;
    }

    private static function resolvedTenantId(?string $tenantId): ?string
    {
        $candidate = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;

        if ($candidate === null && function_exists('tenancy') && tenancy()->initialized) {
            $current = tenant();
            if ($current !== null) {
                $candidate = (string) $current->getTenantKey();
            }
        }

        if ($candidate === null || $candidate === '') {
            return null;
        }

        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            throw new DomainException('Tenant id cannot contain a path separator in an S3 inbound key.');
        }

        return $candidate;
    }

    private static function normalizeRelative(string $relativePath): string
    {
        $relative = ltrim(str_replace('\\', '/', $relativePath), '/');
        $relative = self::stripTenantPrefix($relative);

        if (str_starts_with($relative, 'inbound/') && ! str_starts_with($relative, 'epcis/')) {
            // Hub key fed into local normalize — treat as epcis/inbound/{file}.
            $relative = 'epcis/'.$relative;
        }

        if (! str_starts_with($relative, 'epcis/')) {
            $relative = 'epcis/'.$relative;
        }

        return $relative;
    }

    private static function stripTenantPrefix(string $path): string
    {
        if (preg_match('#^tenants/[^/]+/(epcis/.+)$#', $path, $matches) === 1) {
            return $matches[1];
        }

        return $path;
    }
}
