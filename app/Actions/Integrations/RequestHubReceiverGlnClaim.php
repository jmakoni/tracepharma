<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\HubReceiverGlnClaimRequestStatus;
use App\Models\Admin;
use App\Models\EpcisHubRoute;
use App\Models\HubReceiverGlnClaimRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\HubReceiverGlnClaimReviewRequestedNotification;
use App\Support\Auth\Permissions;
use App\Support\EpcisHub\ClaimableReceiverGlns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

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

        $identity = [
            'tenant_id' => $tenant->getKey(),
            'provider' => $provider,
            'gln' => $normalizedGln,
        ];

        $request = DB::connection((new HubReceiverGlnClaimRequest)->getConnectionName())
            ->transaction(function () use ($identity, $reason, $requestingUser): HubReceiverGlnClaimRequest {
                $request = HubReceiverGlnClaimRequest::query()
                    ->where($identity)
                    ->lockForUpdate()
                    ->first();

                $request ??= new HubReceiverGlnClaimRequest($identity);

                $request->forceFill([
                    'status' => HubReceiverGlnClaimRequestStatus::Pending,
                    'reason' => $reason,
                    'requested_by' => $this->requestedBy($requestingUser),
                    'reviewed_by_admin_id' => null,
                    'reviewed_at' => null,
                    'review_note' => null,
                ])->save();

                return $request;
            });

        $this->notifyReviewers($request);

        return $request;
    }

    /**
     * Alert platform reviewers without allowing notification failures to block the request.
     */
    private function notifyReviewers(HubReceiverGlnClaimRequest $request): void
    {
        $cacheKey = 'hub_receiver_gln_claim_review_requested:'.$request->getKey();

        if (Cache::has($cacheKey)) {
            return;
        }

        $resume = tenancy()->initialized ? tenant() : null;

        if ($resume !== null) {
            tenancy()->end();
        }

        try {
            $notification = HubReceiverGlnClaimReviewRequestedNotification::fromRequest($request);
            $admins = Admin::permission(Permissions::TenantsManage)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, $notification);
            }

            $supportEmail = config('tracepharma.platform_support_email');

            if (is_string($supportEmail) && $supportEmail !== '') {
                Notification::route('mail', $supportEmail)->notify($notification);
            }

            Cache::put($cacheKey, now()->toIso8601String(), now()->addHour());
        } catch (Throwable) {
            // Reviewer alerts must never block claim-request persistence.
        } finally {
            if ($resume !== null) {
                tenancy()->initialize($resume);
            }
        }
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
