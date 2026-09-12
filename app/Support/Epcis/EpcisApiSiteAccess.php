<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Exceptions\DuplicateEpcisUploadException;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

/**
 * SiteAccess enforcement for EPCIS API upload/show parity.
 */
final class EpcisApiSiteAccess
{
    public function __construct(
        private readonly ResolveEpcisUploadShippingSites $resolveUploadShippingSites,
    ) {}

    public function findDuplicate(string $absolutePath, string $direction): ?EpcisDocument
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        $sha256 = hash_file('sha256', $absolutePath);
        if ($sha256 === false) {
            return null;
        }

        return EpcisDocument::query()
            ->where('file_sha256', $sha256)
            ->where('direction', $direction)
            ->whereNotIn('status', ['error', 'voided'])
            ->first();
    }

    /**
     * Fail closed for site-restricted token owners: unknown ship-from/to is denied,
     * and known sites must pass SiteAccess.
     */
    public function assertStoreAllowed(User $user, string $absolutePath, string $direction): void
    {
        if ($user->can(Permissions::SitesAccessAll)) {
            return;
        }

        $sites = $this->resolveUploadShippingSites->handle($absolutePath);

        if ($direction === 'outbound') {
            $shipFromSiteId = $sites['ship_from_site_id'];
            if ($shipFromSiteId === null) {
                throw new AuthorizationException('You do not have access to the ship-from site for this EPCIS document.');
            }
            if (! SiteAccess::canAccessShipFromSite($user, $shipFromSiteId)) {
                throw new AuthorizationException('You do not have access to the ship-from site for this EPCIS document.');
            }

            return;
        }

        $shipToSiteId = $sites['ship_to_site_id'];
        if ($shipToSiteId === null) {
            throw new AuthorizationException('You do not have access to the ship-to site for this EPCIS document.');
        }
        if (! SiteAccess::canAccessShipToSite($user, $shipToSiteId)) {
            throw new AuthorizationException('You do not have access to the ship-to site for this EPCIS document.');
        }
    }

    public function callerCanAccessDocument(User $user, EpcisDocument $document, string $direction): bool
    {
        if ($user->can(Permissions::SitesAccessAll)) {
            return true;
        }

        if ($direction === 'outbound') {
            return SiteAccess::canAccessShipFromSite($user, $document->ship_from_site_id);
        }

        return SiteAccess::canAccessShipToSite($user, $document->ship_to_site_id);
    }

    /**
     * @param  array<string, mixed>  $visibleFields
     */
    public function duplicateJsonResponse(
        DuplicateEpcisUploadException $exception,
        User $user,
        string $direction,
        array $visibleFields = [],
    ): JsonResponse {
        $existing = $exception->existing;

        if (! $this->callerCanAccessDocument($user, $existing, $direction)) {
            return response()->json([
                'message' => 'EPCIS document already received.',
                'duplicate' => true,
            ], 409);
        }

        return response()->json(array_merge([
            'message' => 'EPCIS document already received.',
            'document_id' => $existing->getKey(),
            'document_uuid' => $existing->document_uuid,
            'status' => $existing->status,
            'duplicate' => true,
        ], $visibleFields), 409);
    }
}
