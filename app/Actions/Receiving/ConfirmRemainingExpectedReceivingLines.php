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

final class ConfirmRemainingExpectedReceivingLines
{
    public function __construct(
        private readonly ConfirmReceivingScan $confirmReceivingScan,
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

            return $this->confirmRemainingChildrenOfActiveParent($session, $userId, $unpack);
        }

        $parents = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'parent')
            ->where('status', 'expected')
            ->with('epc')
            ->orderBy('id')
            ->get();

        if ($parents->isEmpty()) {
            return [
                'confirmed' => 0,
                'skipped' => 0,
                'blockers' => [],
            ];
        }

        $blockedEpcIds = $this->receivingGate->epcIdsBlockedByOpenHold(
            $parents->pluck('epc_id')->map(fn ($id): int => (int) $id)->all(),
        );
        $blockedSet = array_flip($blockedEpcIds);

        $autoConfirmChildren = ReceivingPolicy::forTenant(tenant())->defaultAutoConfirmChildren();
        $confirmed = 0;
        $skipped = 0;
        $blockers = [];

        foreach ($parents as $line) {
            $epcId = (int) $line->epc_id;
            if (isset($blockedSet[$epcId])) {
                $skipped++;

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

        if ($skipped > 0 && $blockedEpcIds !== []) {
            $blockers[] = 'Skipped parent line(s) under open quarantine hold.';
        }

        return [
            'confirmed' => $confirmed,
            'skipped' => $skipped,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array{confirmed: int, skipped: int, blockers: list<string>}
     */
    private function confirmRemainingChildrenOfActiveParent(ReceivingSession $session, ?int $userId, bool $unpack = false): array
    {
        $children = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'child')
            ->where('parent_epc_id', $session->active_parent_epc_id)
            ->where('status', 'expected')
            ->with('epc')
            ->orderBy('id')
            ->get();

        if ($children->isEmpty()) {
            return [
                'confirmed' => 0,
                'skipped' => 0,
                'blockers' => [],
            ];
        }

        $blockedEpcIds = $this->receivingGate->epcIdsBlockedByOpenHold(
            $children->pluck('epc_id')->map(fn ($id): int => (int) $id)->all(),
        );
        $blockedSet = array_flip($blockedEpcIds);

        $confirmed = 0;
        $skipped = 0;
        $blockers = [];

        foreach ($children as $line) {
            $epcId = (int) $line->epc_id;
            if (isset($blockedSet[$epcId])) {
                $skipped++;

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
                false,
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

        if ($skipped > 0 && $blockedEpcIds !== []) {
            $blockers[] = 'Skipped child line(s) under open quarantine hold.';
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
