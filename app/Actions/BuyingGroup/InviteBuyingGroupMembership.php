<?php

declare(strict_types=1);

namespace App\Actions\BuyingGroup;

use App\Enums\BuyingGroupMembershipStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\BuyingGroupMember;
use App\Models\BuyingGroupMembership;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BuyingGroupMembershipInviteNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Throwable;

class InviteBuyingGroupMembership
{
    public function invite(
        Tenant $buyingGroupTenant,
        Tenant $memberTenant,
        User $invitingUser,
        ?int $bgMemberLocalId = null,
    ): BuyingGroupMembership {
        $this->assertEligible($buyingGroupTenant, $memberTenant);

        if ($bgMemberLocalId !== null) {
            $this->assertRosterRowExists($buyingGroupTenant, $bgMemberLocalId);
        }

        $actor = $this->actorLabel($invitingUser);

        $membership = DB::connection((new BuyingGroupMembership)->getConnectionName())
            ->transaction(function () use ($buyingGroupTenant, $memberTenant, $bgMemberLocalId, $actor): BuyingGroupMembership {
                $membership = BuyingGroupMembership::query()
                    ->where('buying_group_tenant_id', $buyingGroupTenant->getKey())
                    ->where('member_tenant_id', $memberTenant->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($membership?->status === BuyingGroupMembershipStatus::Active) {
                    throw new RuntimeException(
                        'This pharmacy tenant is already an active member of this buying group.',
                    );
                }

                if ($membership?->status === BuyingGroupMembershipStatus::Pending) {
                    throw new RuntimeException(
                        'An invite is already pending for this pharmacy tenant.',
                    );
                }

                $membership ??= new BuyingGroupMembership([
                    'buying_group_tenant_id' => (string) $buyingGroupTenant->getKey(),
                    'member_tenant_id' => (string) $memberTenant->getKey(),
                ]);

                $membership->forceFill([
                    'status' => BuyingGroupMembershipStatus::Pending,
                    'bg_member_local_id' => $bgMemberLocalId ?? $membership->bg_member_local_id,
                    'consent_version' => null,
                    'invited_by' => $actor,
                    'invited_at' => now(),
                    'accepted_by' => null,
                    'accepted_at' => null,
                    'revoked_by' => null,
                    'revoked_at' => null,
                    'revoke_reason' => null,
                ])->save();

                return $membership->fresh() ?? $membership;
            });

        $this->notifyMemberOwners($membership, $buyingGroupTenant, $memberTenant);

        return $membership;
    }

    private function assertEligible(Tenant $buyingGroupTenant, Tenant $memberTenant): void
    {
        if ($buyingGroupTenant->profile !== TenantProfile::BuyingGroup) {
            throw new RuntimeException('Only a buying-group tenant can invite members.');
        }

        if ((string) $buyingGroupTenant->status !== 'active') {
            throw new RuntimeException('The buying-group tenant must be active to invite members.');
        }

        if ($memberTenant->profile !== TenantProfile::Pharmacy) {
            throw new RuntimeException('Hard membership is limited to pharmacy tenants.');
        }

        if ((string) $memberTenant->status !== 'active') {
            throw new RuntimeException('The member pharmacy tenant must be active.');
        }

        if ((string) $buyingGroupTenant->getKey() === (string) $memberTenant->getKey()) {
            throw new RuntimeException('A buying group cannot invite itself.');
        }
    }

    private function assertRosterRowExists(Tenant $buyingGroupTenant, int $bgMemberLocalId): void
    {
        $exists = (bool) $buyingGroupTenant->run(function () use ($bgMemberLocalId): bool {
            return BuyingGroupMember::query()->whereKey($bgMemberLocalId)->exists();
        });

        if (! $exists) {
            throw new RuntimeException('The member roster row was not found in this buying group.');
        }
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

    private function notifyMemberOwners(
        BuyingGroupMembership $membership,
        Tenant $buyingGroupTenant,
        Tenant $memberTenant,
    ): void {
        try {
            $memberTenant->run(function () use ($membership, $buyingGroupTenant, $memberTenant): void {
                try {
                    $owners = User::role(TenantRole::Owner->value)->get();
                } catch (RoleDoesNotExist) {
                    $owners = collect();
                }

                if ($owners->isEmpty()) {
                    return;
                }

                Notification::send($owners, new BuyingGroupMembershipInviteNotification(
                    buyingGroupName: (string) ($buyingGroupTenant->name ?? 'Buying group'),
                    buyingGroupTenantId: (string) $buyingGroupTenant->getKey(),
                    membershipId: (int) $membership->getKey(),
                    memberTenantId: (string) $memberTenant->getKey(),
                ));
            });
        } catch (Throwable) {
            // Notification delivery must never block the invite.
        }
    }
}
