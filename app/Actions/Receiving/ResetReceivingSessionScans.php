<?php

namespace App\Actions\Receiving;

use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Clear floor confirms for an in-progress receiving session.
 *
 * - inbound_asn: re-seed ASN expected parent lines from the linked document
 * - scan_first: clear confirmed lines (no ASN document required)
 * - transfer_receive: blocked once transfer receive events exist; otherwise
 *   restore expected lines and clear transferring received marks
 */
final class ResetReceivingSessionScans
{
    public function __construct(
        private readonly OpenReceivingSessionFromDocument $openReceivingSessionFromDocument,
        private readonly RevertTransferReceiveReceivingMarks $revertTransferReceiveReceivingMarks,
    ) {}

    public function handle(ReceivingSession $session, ?int $actorId = null): ReceivingSession
    {
        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            $this->assertCanAccessSessionSite($user, $session);
        }

        $session = ReceivingSession::query()
            ->with(['document', 'transferringSession'])
            ->whereKey($session->getKey())
            ->firstOrFail();

        if ($session->receiving_events_generated_at !== null) {
            throw new DomainException('Cannot reset scans: receiving EPCIS events were already generated for this session.');
        }

        if ($session->isTransferReceive()) {
            $transfer = $session->transferringSession;

            if ($transfer !== null && $transfer->receive_events_generated_at !== null) {
                throw new DomainException('Cannot reset scans: transfer receive EPCIS events were already generated.');
            }

            if ($session->status === 'completed') {
                if ($transfer === null) {
                    throw new DomainException('Cannot reset scans: this receiving session is already complete.');
                }

                return $this->resetTransferReceive($session, $actorId);
            }
        } elseif ($session->status === 'completed') {
            throw new DomainException('Cannot reset scans: this receiving session is already complete.');
        }

        if (! in_array($session->status, ['open', 'in_progress'], true)) {
            throw new DomainException("Cannot reset scans: session status [{$session->status}] is not resettable.");
        }

        if ($session->isTransferReceive()) {
            return $this->resetTransferReceive($session, $actorId);
        }

        if ($session->isScanFirst()) {
            return $this->resetScanFirst($session, $actorId);
        }

        return $this->resetInboundAsn($session, $actorId);
    }

    private function resetScanFirst(ReceivingSession $session, ?int $actorId): ReceivingSession
    {
        $reset = DB::transaction(function () use ($session): ReceivingSession {
            $session = ReceivingSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->status === 'completed' || $session->receiving_events_generated_at !== null) {
                throw new DomainException('Cannot reset scans: session completed or events were generated during reset.');
            }

            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->delete();

            $session->forceFill([
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'completed_at' => null,
                'matched_epcis_document_id' => null,
            ])->save();

            return $session->refresh();
        });

        Log::info('receiving.session.scans_reset', [
            'receiving_session_id' => $reset->getKey(),
            'session_kind' => $reset->session_kind?->value,
            'actor_id' => $actorId,
        ]);

        return $reset;
    }

    private function resetTransferReceive(ReceivingSession $session, ?int $actorId): ReceivingSession
    {
        $transfer = $session->transferringSession;

        if ($transfer !== null && $transfer->receive_events_generated_at !== null) {
            throw new DomainException('Cannot reset scans: transfer receive EPCIS events were already generated.');
        }

        $reset = DB::transaction(function () use ($session, $transfer): ReceivingSession {
            $session = ReceivingSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->receiving_events_generated_at !== null) {
                throw new DomainException('Cannot reset scans: receiving EPCIS events were generated during reset.');
            }

            if ($transfer !== null) {
                $transfer = TransferringSession::query()
                    ->whereKey($transfer->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($transfer->receive_events_generated_at !== null) {
                    throw new DomainException('Cannot reset scans: transfer receive EPCIS events were generated during reset.');
                }
            }

            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->update([
                    'status' => 'expected',
                    'scan_raw' => null,
                    'confirmed_at' => null,
                    'confirmed_by' => null,
                    'ilmd_mismatch_json' => null,
                    'updated_at' => now(),
                ]);

            if ($transfer !== null) {
                $this->revertTransferReceiveReceivingMarks->handle($session);
            }

            $expectedCount = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->count();

            $session->forceFill([
                'status' => 'open',
                'expected_parent_count' => $expectedCount,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'completed_at' => null,
            ])->save();

            return $session->refresh();
        });

        Log::info('receiving.session.scans_reset', [
            'receiving_session_id' => $reset->getKey(),
            'session_kind' => $reset->session_kind?->value,
            'transferring_session_id' => $reset->transferring_session_id,
            'actor_id' => $actorId,
        ]);

        return $reset;
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

    private function resetInboundAsn(ReceivingSession $session, ?int $actorId): ReceivingSession
    {
        $document = $session->document;

        if ($document === null) {
            throw new DomainException('Cannot reset scans: receiving session has no linked EPCIS document.');
        }

        $rootParentIds = $this->openReceivingSessionFromDocument->resolveRootParentEpcIds($document);

        $reset = DB::transaction(function () use ($session, $rootParentIds): ReceivingSession {
            $session = ReceivingSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->status === 'completed' || $session->receiving_events_generated_at !== null) {
                throw new DomainException('Cannot reset scans: session completed or events were generated during reset.');
            }

            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->delete();

            $now = now();
            $rows = [];

            foreach ($rootParentIds as $epcId) {
                $rows[] = [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $epcId,
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'expected',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                ReceivingScanLine::query()->insert($rows);
            }

            $session->forceFill([
                'status' => 'open',
                'expected_parent_count' => count($rootParentIds),
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'completed_at' => null,
            ])->save();

            return $session->refresh();
        });

        Log::info('receiving.session.scans_reset', [
            'receiving_session_id' => $reset->getKey(),
            'epcis_document_id' => $reset->epcis_document_id,
            'actor_id' => $actorId,
            'expected_parent_count' => $reset->expected_parent_count,
        ]);

        return $reset;
    }
}
