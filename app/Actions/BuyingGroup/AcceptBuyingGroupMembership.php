<?php

declare(strict_types=1);

namespace App\Actions\BuyingGroup;

use App\Enums\BuyingGroupMemberStatus;
use App\Enums\BuyingGroupMembershipStatus;
use App\Enums\TenantProfile;
use App\Models\BuyingGroupMember;
use App\Models\BuyingGroupMembership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AcceptBuyingGroupMembership
{
    public function accept(BuyingGroupMembership $membership, User $acceptingUser): BuyingGroupMembership
    {
        $membership->refresh();

        if (! $membership->isPending()) {
            throw new RuntimeException('Only pending buying-group invites can be accepted.');
        }

        $buyingGroupTenant = $membership->buyingGroupTenant;
        $memberTenant = $membership->memberTenant;

        if ($buyingGroupTenant === null || $memberTenant === null) {
            throw new RuntimeException('Buying group or member tenant is missing for this invite.');
        }

        if ($buyingGroupTenant->profile !== TenantProfile::BuyingGroup
            || (string) $buyingGroupTenant->status !== 'active') {
            throw new RuntimeException('The buying-group tenant is not eligible for membership.');
        }

        if ($memberTenant->profile !== TenantProfile::Pharmacy
            || (string) $memberTenant->status !== 'active') {
            throw new RuntimeException('Only an active pharmacy tenant can accept this invite.');
        }

        $actor = $this->actorLabel($acceptingUser);

        $updated = DB::connection((new BuyingGroupMembership)->getConnectionName())
            ->transaction(function () use ($membership, $actor): BuyingGroupMembership {
                /** @var BuyingGroupMembership $locked */
                $locked = BuyingGroupMembership::query()
                    ->whereKey($membership->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->isPending()) {
                    throw new RuntimeException('Only pending buying-group invites can be accepted.');
                }

                $locked->forceFill([
                    'status' => BuyingGroupMembershipStatus::Active,
                    'consent_version' => BuyingGroupMembership::CONSENT_VERSION,
                    'accepted_by' => $actor,
                    'accepted_at' => now(),
                    'revoked_by' => null,
                    'revoked_at' => null,
                    'revoke_reason' => null,
                ])->save();

                return $locked->fresh() ?? $locked;
            });

        $this->syncRosterHardLink($updated, $buyingGroupTenant);
        $this->markMemberConsent($memberTenant);

        return $updated;
    }

    private function syncRosterHardLink(BuyingGroupMembership $membership, Tenant $buyingGroupTenant): void
    {
        $buyingGroupTenant->run(function () use ($membership): void {
            $memberTenantId = (string) $membership->member_tenant_id;
            $localId = $membership->bg_member_local_id;

            $roster = $localId !== null
                ? BuyingGroupMember::query()->whereKey($localId)->first()
                : null;

            if ($roster === null) {
                $roster = BuyingGroupMember::query()
                    ->where('member_tenant_id', $memberTenantId)
                    ->first();
            }

            if ($roster === null) {
                $roster = BuyingGroupMember::query()->create([
                    'name' => $membership->memberTenant?->name ?? 'Linked pharmacy',
                    'member_tenant_id' => $memberTenantId,
                    'status' => BuyingGroupMemberStatus::Active,
                ]);
            } else {
                $roster->forceFill([
                    'member_tenant_id' => $memberTenantId,
                    'status' => BuyingGroupMemberStatus::Active,
                ])->save();
            }

            if ($membership->bg_member_local_id !== (int) $roster->getKey()) {
                $membership->forceFill([
                    'bg_member_local_id' => (int) $roster->getKey(),
                ])->save();
            }
        });
    }

    private function markMemberConsent(Tenant $memberTenant): void
    {
        TenantSettings::forTenant($memberTenant)
            ->setBuyingGroupNetworkConsent(true);

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
