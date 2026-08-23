<?php

namespace App\Actions\Receiving;

use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CloseOpenToteReceiving
{
    public function __construct(
        private readonly CompleteReceivingSession $completeReceivingSession,
    ) {}

    /**
     * @return array{cleared: bool, short_closed: bool, parent_epc_id: int}
     */
    public function handle(ReceivingSession $session, ?int $userId = null, bool $unpack = false): array
    {
        if (! TenantFeatures::forTenant(tenant())->supportsReceiving()) {
            throw new DomainException('Receiving is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $session = $session->fresh() ?? $session;

        $actor = $this->resolveActor($userId);
        if ($actor !== null) {
            $this->assertCanAccessSessionSite($actor, $session);
        }

        if (ReceivingPolicy::forTenant(tenant())->edgeMode() !== ReceivingEdgeMode::OpenTote) {
            throw new DomainException('Close tote is only available in open tote mode.');
        }

        $result = DB::transaction(function () use ($session): array {
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->active_parent_epc_id === null) {
                throw new DomainException('No tote is open.');
            }

            $parentEpcId = (int) $session->active_parent_epc_id;

            $hasExpectedChildren = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->where('parent_epc_id', $parentEpcId)
                ->where('status', 'expected')
                ->exists();

            $updates = ['active_parent_epc_id' => null];

            if ($hasExpectedChildren) {
                $closed = $session->shortClosedParentEpcIdList();
                $closed[] = $parentEpcId;
                $updates['short_closed_parent_epc_ids'] = array_values(array_unique($closed));
            }

            $session->forceFill($updates)->save();

            return [
                'cleared' => true,
                'short_closed' => $hasExpectedChildren,
                'parent_epc_id' => $parentEpcId,
                'session' => $session->refresh(),
            ];
        });

        $session = $result['session'];
        unset($result['session']);

        if ($session->isReadyToCompleteInboundAsn()) {
            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $this->completeReceivingSession->handle($session->fresh(), $userId, unpack: $unpack);
        }

        return $result;
    }

    private function assertCanAccessSessionSite(User $user, ReceivingSession $session): void
    {
        if ($session->site_id === null) {
            if (! $user->can(Permissions::SitesAccessAll)) {
                throw new AuthorizationException('You do not have access to this receiving session.');
            }

            return;
        }

        SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
    }

    private function resolveActor(?int $userId): ?User
    {
        $user = auth()->user();
        if ($user instanceof User) {
            return $user;
        }

        if ($userId === null) {
            return null;
        }

        $resolved = User::query()->find($userId);

        return $resolved instanceof User ? $resolved : null;
    }
}
