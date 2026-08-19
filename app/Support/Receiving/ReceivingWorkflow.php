<?php

namespace App\Support\Receiving;

use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;

/**
 * Thin facade over the receiving Actions + policy for callers that don't need
 * to reach for the individual Actions. No business logic lives here — it only
 * delegates to ReceivingPolicy / ConfirmReceivingScan / CompleteReceivingSession.
 */
final class ReceivingWorkflow
{
    public function __construct(
        private readonly ConfirmReceivingScan $confirmReceivingScan,
        private readonly CompleteReceivingSession $completeReceivingSession,
    ) {}

    public function policy(?Tenant $tenant = null): ReceivingPolicy
    {
        return ReceivingPolicy::forTenant($tenant ?? tenant());
    }

    /**
     * @return array{ok: bool, message: string, line: mixed, epc: mixed, effect: string}
     */
    public function confirmScan(
        ReceivingSession $session,
        string $scan,
        ?int $userId = null,
        ?bool $autoConfirmChildren = null,
    ): array {
        $autoConfirmChildren ??= $this->policy()->defaultAutoConfirmChildren();

        return $this->confirmReceivingScan->handle($session, $scan, $userId, $autoConfirmChildren);
    }

    public function complete(ReceivingSession $session, ?int $actorId = null, bool $unpack = false): ReceivingSession
    {
        return $this->completeReceivingSession->handle($session, $actorId, $unpack);
    }
}
