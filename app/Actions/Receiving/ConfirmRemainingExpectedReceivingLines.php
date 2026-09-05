<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ConfirmRemainingExpectedReceivingLines
{
    public function __construct(
        private readonly ConfirmReceivingScan $confirmReceivingScan,
        private readonly CompleteReceivingSession $completeReceivingSession,
        private readonly ReceivingGate $receivingGate,
    ) {}

    /**
     * @return array{confirmed: int, skipped: int, blockers: list<string>}
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

            $result = $this->confirmRemainingChildrenOfActiveParent($session, $userId, $unpack);
        } else {
            $autoConfirmChildren = ReceivingPolicy::forTenant(tenant())->defaultAutoConfirmChildren();
            $parentResult = $this->confirmExpectedLinesFromQuery(
                $session,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('line_role', 'parent')
                    ->where('status', 'expected'),
                $userId,
                $unpack,
                $autoConfirmChildren,
                'Skipped parent line(s) under open quarantine hold.',
            );

            // Open-count parent confirm does not auto-confirm units. Accept remaining
            // still has to take leftover expected children or the session cannot complete
            // and a second click finds no expected parents.
            $childResult = $this->confirmExpectedLinesFromQuery(
                $session,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('line_role', 'child')
                    ->where('status', 'expected'),
                $userId,
                $unpack,
                false,
                'Skipped child line(s) under open quarantine hold.',
            );

            $result = [
                'confirmed' => $parentResult['confirmed'] + $childResult['confirmed'],
                'skipped' => $parentResult['skipped'] + $childResult['skipped'],
                'blockers' => [...$parentResult['blockers'], ...$childResult['blockers']],
            ];
        }

        // Accept remaining is an explicit operator action: when the ASN is ready,
        // finish like Close tote (not silent last-scan auto-complete).
        $session = $session->fresh() ?? $session;
        if (
            $result['confirmed'] > 0
            && $result['blockers'] === []
            && $session->isInboundAsn()
            && $session->status !== 'completed'
            && $session->isReadyToCompleteInboundAsn()
        ) {
            $this->completeReceivingSession->handle($session, $userId, unpack: $unpack);
        }

        return $result;
    }

    /**
     * @return array{confirmed: int, skipped: int, blockers: list<string>}
     */
    private function confirmRemainingChildrenOfActiveParent(ReceivingSession $session, ?int $userId, bool $unpack = false): array
    {
        return $this->confirmExpectedLinesFromQuery(
            $session,
            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->where('parent_epc_id', $session->active_parent_epc_id)
                ->where('status', 'expected'),
            $userId,
            $unpack,
            false,
            'Skipped child line(s) under open quarantine hold.',
        );
    }

    /**
     * @param  Builder<ReceivingScanLine>  $query
     * @return array{confirmed: int, skipped: int, blockers: list<string>}
     */
    private function confirmExpectedLinesFromQuery(
        ReceivingSession $session,
        Builder $query,
        ?int $userId,
        bool $unpack,
        bool $autoConfirmChildren,
        string $quarantineBlocker,
    ): array {
        $confirmed = 0;
        $skipped = 0;
        $blockers = [];
        $hadQuarantineSkip = false;

        $query
            ->with('epc')
            ->orderBy('id')
            ->chunkById(500, function (Collection $lines) use (
                $session,
                $userId,
                $unpack,
                $autoConfirmChildren,
                &$confirmed,
                &$skipped,
                &$blockers,
                &$hadQuarantineSkip,
            ): void {
                $blockedEpcIds = $this->receivingGate->epcIdsBlockedByOpenHold(
                    $lines->pluck('epc_id')->map(fn ($id): int => (int) $id)->all(),
                );
                $blockedSet = array_flip($blockedEpcIds);

                foreach ($lines as $line) {
                    $epcId = (int) $line->epc_id;
                    if (isset($blockedSet[$epcId])) {
                        $skipped++;
                        $hadQuarantineSkip = true;

                        continue;
                    }

                    $scan = $line->epc?->epc_uri ?? $line->scan_raw;
                    if (! is_string($scan) || $scan === '') {
                        $skipped++;

                        continue;
                    }

                    $result = $this->confirmReceivingScan->handle(
                        $session->fresh() ?? $session,
                        $scan,
                        $userId,
                        $autoConfirmChildren,
                        unpack: $unpack,
                    );

                    if ($result['ok'] ?? false) {
                        $confirmed++;

                        continue;
                    }

                    $skipped++;
                    if (filled($result['message'] ?? null)) {
                        $blockers[] = (string) $result['message'];
                    }
                }
            });

        if ($hadQuarantineSkip) {
            $blockers[] = $quarantineBlocker;
        }

        return [
            'confirmed' => $confirmed,
            'skipped' => $skipped,
            'blockers' => $blockers,
        ];
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
