<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\ExpectedInboundOrderHeader;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

final class ConfirmRemainingExpectedReceivingLines
{
    public function __construct(
        private readonly CompleteReceivingSession $completeReceivingSession,
        private readonly ReceivingGate $receivingGate,
        private readonly SeedReceivingAsnParentChildren $seedReceivingAsnParentChildren,
        private readonly FlagManualReceivingException $flagManualReceivingException,
    ) {}

    /**
     * @return array{confirmed: int, skipped: int, blockers: list<string>}
     */
    public function handle(
        ReceivingSession $session,
        ?int $userId = null,
        bool $unpack = false,
        ?string $reason = null,
        bool $sealAcknowledged = false,
    ): array {
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

        $settings = TenantSettings::forTenant(tenant());
        $policy = ReceivingPolicy::forTenant(tenant());
        $requiresReason = $settings->requireAcceptRemainingReason();
        $requiresSeal = $settings->requireSealQuestion() && $policy->defaultAutoConfirmChildren();
        $reason = is_string($reason) ? trim($reason) : '';

        if ($requiresReason || $requiresSeal) {
            if ($reason === '') {
                throw new DomainException(
                    'A reason is required to accept remaining unscanned expected lines.',
                );
            }
        }

        if ($requiresSeal && ! $sealAcknowledged) {
            throw new DomainException(
                'Confirm seal intact before accepting remaining sealed hierarchies.',
            );
        }

        if ($session->epcis_document_id !== null) {
            $document = EpcisDocument::query()->find($session->epcis_document_id);
            if ($document !== null) {
                $blockingCase = $this->receivingGate->documentBlockedByOpenException($document);
                if ($blockingCase !== null) {
                    $type = $blockingCase->type?->name ?? $blockingCase->type?->code ?? 'exception';

                    return [
                        'confirmed' => 0,
                        'skipped' => 0,
                        'blockers' => [
                            "Cannot confirm receive: open document-wide exception #{$blockingCase->getKey()} ({$type}) blocks this file until resolved.",
                        ],
                    ];
                }
            }
        }

        if (ReceivingPolicy::forTenant(tenant())->edgeMode() === ReceivingEdgeMode::OpenTote) {
            if ($session->active_parent_epc_id === null) {
                throw new DomainException('Accept remaining is disabled until a tote is open');
            }

            $result = $this->acceptRemainingOpenTote($session, $userId);
        } else {
            $result = $this->acceptRemainingFromScannedParents($session, $userId, $actor, $reason);
        }

        $session = $session->fresh() ?? $session;
        $missingParentEpcIds = $this->unscannedExpectedParentEpcIds($session);

        if (
            $result['blockers'] === []
            && $missingParentEpcIds === []
            && $session->isInboundAsn()
            && $session->status !== 'completed'
            && $session->isReadyToCompleteInboundAsn()
        ) {
            $this->completeReceivingSession->handle($session, $userId, unpack: $unpack);
            $session = $session->fresh() ?? $session;
        } elseif ($result['confirmed'] > 0) {
            ExpectedInboundOrderHeader::refreshShipmentRollups($session);
        }

        if ($reason !== '' || $requiresReason || $requiresSeal) {
            $this->persistAcceptRemainingAudit(
                $session,
                $actor,
                $userId,
                $reason,
                $sealAcknowledged,
                $result,
            );
        }

        return $result;
    }

    /**
     * GS1 inference: infer children only under confirmed (scanned) parents from inbound agg.
     *
     * @return array{confirmed: int, skipped: int, blockers: list<string>}
     */
    private function acceptRemainingFromScannedParents(
        ReceivingSession $session,
        ?int $userId,
        ?User $actor,
        string $reason,
    ): array {
        $confirmed = 0;

        $confirmedParents = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'parent')
            ->where('status', 'confirmed')
            ->with('epc')
            ->orderBy('id')
            ->get();

        foreach ($confirmedParents as $line) {
            if (! $line->epc instanceof Epc) {
                continue;
            }

            $seeded = $this->seedReceivingAsnParentChildren->handle(
                $session,
                $line->epc,
                $userId,
                autoConfirmChildren: true,
            );
            $confirmed += $seeded['confirmed_children'];
        }

        $missingParentEpcIds = $this->unscannedExpectedParentEpcIds($session);
        $blockers = [];

        if ($missingParentEpcIds !== []) {
            $this->flagManualReceivingException->ensureShortageFromShortClose(
                $session,
                $missingParentEpcIds,
                $actor,
                $reason !== ''
                    ? $reason
                    : 'Accept remaining: unscanned expected parent container(s).',
            );
            $blockers[] = 'Unscanned expected parent container(s) recorded as shortage.';
        }

        return [
            'confirmed' => $confirmed,
            'skipped' => count($missingParentEpcIds),
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array{confirmed: int, skipped: int, blockers: list<string>}
     */
    private function acceptRemainingOpenTote(ReceivingSession $session, ?int $userId): array
    {
        $parentEpc = Epc::query()->find($session->active_parent_epc_id);
        if ($parentEpc === null) {
            throw new DomainException('Active tote parent EPC not found.');
        }

        $seeded = $this->seedReceivingAsnParentChildren->handle(
            $session,
            $parentEpc,
            $userId,
            autoConfirmChildren: true,
        );

        return [
            'confirmed' => $seeded['confirmed_children'],
            'skipped' => 0,
            'blockers' => [],
        ];
    }

    /**
     * @return list<int>
     */
    private function unscannedExpectedParentEpcIds(ReceivingSession $session): array
    {
        return ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'parent')
            ->where('status', 'expected')
            ->whereNotNull('epc_id')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array{confirmed: int, skipped: int, blockers: list<string>}  $result
     */
    private function persistAcceptRemainingAudit(
        ReceivingSession $session,
        ?User $actor,
        ?int $userId,
        string $reason,
        bool $sealAcknowledged,
        array $result,
    ): void {
        $properties = [
            'receiving_session_id' => $session->getKey(),
            'session_kind' => $session->session_kind?->value,
            'confirmed' => $result['confirmed'],
            'skipped' => $result['skipped'],
            'reason' => $reason !== '' ? $reason : null,
            'seal_acknowledged' => $sealAcknowledged,
            'actor_id' => $actor?->getKey() ?? $userId,
        ];

        Log::info('receiving.session.accept_remaining', $properties);

        $logger = activity()
            ->performedOn($session)
            ->withProperties($properties);

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $logger->log('receiving_accept_remaining');
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
