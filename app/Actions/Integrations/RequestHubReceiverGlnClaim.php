<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\HubReceiverGlnClaimRequestStatus;
use App\Models\EpcisHubRoute;
use App\Models\HubReceiverGlnClaimRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EpcisHub\ClaimableReceiverGlns;
use RuntimeException;

class RequestHubReceiverGlnClaim
{
    public function __construct(
        private readonly ClaimTenantHubReceiverGln $claimAction,
    ) {}

    public function request(
        Tenant $tenant,
        string $provider,
        string $gln,
        string $reason,
        User $requestingUser,
    ): HubReceiverGlnClaimRequest {
        $provider = strtolower(trim($provider));
        $normalizedGln = ClaimableReceiverGlns::normalizeStoredGln($gln);

        if ($normalizedGln === null) {
            throw new RuntimeException('Receiver GLN must be a 13-digit value.');
        }

        $this->claimAction->assertTenantMayClaim($tenant, $provider);

        if (! ClaimableReceiverGlns::contains($tenant, $normalizedGln)) {
            throw new RuntimeException(
                "GLN [{$normalizedGln}] is not the company GLN or an org-facility site GLN for this tenant.",
            );
        }

        if (EpcisHubRoute::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('provider', $provider)
            ->where('gln', $normalizedGln)
            ->where('claimed_via', EpcisHubRoute::CLAIMED_VIA_ADMIN)
            ->exists()) {
            throw new RuntimeException(
                "GLN [{$normalizedGln}] is already admin-claimed for provider [{$provider}].",
            );
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A reason is required for a hub receiver GLN claim request.');
        }

        $request = HubReceiverGlnClaimRequest::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'provider' => $provider,
                'gln' => $normalizedGln,
                'status' => HubReceiverGlnClaimRequestStatus::Pending,
            ],
            [
                'reason' => $reason,
                'requested_by' => $this->requestedBy($requestingUser),
                'reviewed_by_admin_id' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ],
        );

        // Task 5 will notify platform reviewers after the pending row is persisted.

        return $request;
    }

    private function requestedBy(User $user): string
    {
        $name = trim((string) $user->name);
        $email = trim((string) $user->email);

        if ($name !== '' && $email !== '') {
            return "{$name} ({$email})";
        }

        $label = $name !== '' ? $name : $email;

        if ($label === '') {
            throw new RuntimeException('The requesting user must have a name or email address.');
        }

        return $label;
    }
}
