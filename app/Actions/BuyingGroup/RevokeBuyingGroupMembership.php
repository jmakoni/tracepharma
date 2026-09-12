<?php

declare(strict_types=1);

namespace App\Actions\BuyingGroup;

use App\Enums\BuyingGroupMemberStatus;
use App\Enums\BuyingGroupMembershipStatus;
use App\Models\BuyingGroupMember;
use App\Models\BuyingGroupMembership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RevokeBuyingGroupMembership
{
    public function revoke(
        BuyingGroupMembership $membership,
        User $revokingUser,
        ?string $reason = null,
    ): BuyingGroupMembership {
        $membership->refresh();

        if ($membership->status === BuyingGroupMembershipStatus::Revoked) {
            throw new RuntimeException('This buying-group membership is already revoked.');
        }

        if (! in_array($membership->status, [
            BuyingGroupMembershipStatus::Pending,
            BuyingGroupMembershipStatus::Active,
        ], true)) {
            throw new RuntimeException('This buying-group membership cannot be revoked.');
        }

        $wasActive = $membership->isActive();
        $actor = $this->actorLabel($revokingUser);
        $reason = ($trimmed = trim((string) $reason)) !== '' ? $trimmed : null;

        $updated = DB::connection((new BuyingGroupMembership)->getConnectionName())
            ->transaction(function () use ($membership, $actor, $reason): BuyingGroupMembership {
                /** @var BuyingGroupMembership $locked */
                $locked = BuyingGroupMembership::query()
                    ->whereKey($membership->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === BuyingGroupMembershipStatus::Revoked) {
                    throw new RuntimeException('This buying-group membership is already revoked.');
                }

                $locked->forceFill([
                    'status' => BuyingGroupMembershipStatus::Revoked,
                    'revoked_by' => $actor,
                    'revoked_at' => now(),
                    'revoke_reason' => $reason,
                ])->save();

                return $locked->fresh() ?? $locked;
            });

        $buyingGroupTenant = $updated->buyingGroupTenant;
        $memberTenant = $updated->memberTenant;

        if ($wasActive && $buyingGroupTenant !== null) {
            $this->clearRosterHardLink($updated, $buyingGroupTenant);
        }

        if ($memberTenant !== null) {
            $this->refreshMemberConsentFlag($memberTenant);
        }

        return $updated;
    }

    private function clearRosterHardLink(BuyingGroupMembership $membership, Tenant $buyingGroupTenant): void
    {
        $buyingGroupTenant->run(function () use ($membership): void {
            $query = BuyingGroupMember::query();

            if ($membership->bg_member_local_id !== null) {
                $query->whereKey($membership->bg_member_local_id);
            } else {
                $query->where('member_tenant_id', $membership->member_tenant_id);
            }

            $roster = $query->first();

            if ($roster === null) {
                return;
            }

            $roster->forceFill([
                'member_tenant_id' => null,
                'status' => BuyingGroupMemberStatus::Suspended,
            ])->save();
        });
    }

    private function refreshMemberConsentFlag(Tenant $memberTenant): void
    {
        $stillActive = BuyingGroupMembership::query()
            ->where('member_tenant_id', $memberTenant->getKey())
            ->active()
            ->exists();

        TenantSettings::forTenant($memberTenant)
            ->setBuyingGroupNetworkConsent($stillActive);

        $memberTenant->save();
    }

    private function actorLabel(User $user): string
    {
        $name = trim((string) $user->name);
        $email = trim((string) $user->email);

        if ($name !== '' && $email !== '') {
            return "{$name} ({$email})";
        }

        return $email !== '' ? $email : ($name !== '' ? $name : 'Unknown user');
    }
}
