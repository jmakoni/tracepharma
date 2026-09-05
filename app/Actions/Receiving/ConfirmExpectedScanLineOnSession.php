<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Services\Receiving\ReceivingGate;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;

/**
 * Mark an expected receiving scan line confirmed using floor scan identity
 * from a source line — without re-entering ConfirmReceivingScan.
 *
 * For inbound ASN parents, seeds hierarchy children (like confirmParent) so
 * reconcile/propagate cannot complete with a missing child tree.
 */
final class ConfirmExpectedScanLineOnSession
{
    public function __construct(
        private readonly SeedReceivingAsnParentChildren $seedReceivingAsnParentChildren,
        private readonly ReceivingGate $receivingGate,
    ) {}

    /**
     * @return array{ok: bool, line: ?ReceivingScanLine, message: ?string, needs_completion?: bool}
     */
    public function handle(
        ReceivingSession $session,
        ReceivingScanLine $sourceLine,
        ?int $userId = null,
    ): array {
        $session = $session->fresh() ?? $session;

        if (! in_array($session->status, ['open', 'in_progress'], true)) {
            return [
                'ok' => false,
                'line' => null,
                'message' => 'This receiving session is already closed.',
            ];
        }

        $epcId = (int) $sourceLine->epc_id;

        $targetLine = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('epc_id', $epcId)
            ->first();

        if ($targetLine === null) {
            return [
                'ok' => false,
                'line' => null,
                'message' => "EPC {$epcId} is not on this receiving session.",
            ];
        }

        if ($targetLine->status === 'confirmed') {
            $this->overlayScanIdentity($targetLine, $sourceLine, $userId);

            return [
                'ok' => true,
                'line' => $targetLine->fresh(),
                'message' => null,
                'needs_completion' => false,
            ];
        }

        if ($targetLine->status !== 'expected') {
            return [
                'ok' => false,
                'line' => $targetLine,
                'message' => "EPC {$epcId} is not expected on this receiving session.",
            ];
        }

        return DB::transaction(function () use ($session, $targetLine, $sourceLine, $userId): array {
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $targetLine = ReceivingScanLine::query()->whereKey($targetLine->getKey())->lockForUpdate()->firstOrFail();

            if ($targetLine->status === 'confirmed') {
                $this->overlayScanIdentity($targetLine, $sourceLine, $userId);

                return [
                    'ok' => true,
                    'line' => $targetLine->fresh(),
                    'message' => null,
                    'needs_completion' => false,
                ];
            }

            if ($targetLine->status !== 'expected') {
                return [
                    'ok' => false,
                    'line' => $targetLine,
                    'message' => 'Line is no longer expected.',
                ];
            }

            if ($session->epcis_document_id !== null) {
                $document = EpcisDocument::query()->find($session->epcis_document_id);
                if ($document !== null) {
                    $blockingCase = $this->receivingGate->documentBlockedByOpenException($document);
                    if ($blockingCase !== null) {
                        $type = $blockingCase->type?->name ?? $blockingCase->type?->code ?? 'exception';

                        return [
                            'ok' => false,
                            'line' => $targetLine,
                            'message' => "Cannot confirm receive: open document-wide exception #{$blockingCase->getKey()} ({$type}) blocks this file until resolved.",
                        ];
                    }
                }
            }

            $epc = Epc::query()->find($targetLine->epc_id);
            if ($epc !== null) {
                $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
                if ($hold !== null) {
                    $caseId = $hold->exception_id;
                    $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

                    return [
                        'ok' => false,
                        'line' => $targetLine,
                        'message' => 'Under quarantine'.$suffix.'. Clear or release quarantine before receiving.',
                    ];
                }
            }

            if ($targetLine->line_role === 'child' && ! $this->isParentConfirmed($session, $targetLine)) {
                return [
                    'ok' => false,
                    'line' => $targetLine,
                    'message' => 'Confirm the pallet before scanning units.',
                ];
            }

            $targetLine->forceFill([
                'status' => 'confirmed',
                'scan_raw' => $sourceLine->scan_raw ?? $targetLine->scan_raw,
                'confirmed_at' => $sourceLine->confirmed_at ?? now(),
                'confirmed_by' => $userId ?? $sourceLine->confirmed_by,
                'ilmd_mismatch_json' => $sourceLine->ilmd_mismatch_json ?? $targetLine->ilmd_mismatch_json,
            ])->save();

            $confirmedParents = (int) $session->confirmed_parent_count + ($targetLine->line_role === 'parent' ? 1 : 0);
            $confirmedChildren = (int) $session->confirmed_child_count + ($targetLine->line_role === 'child' ? 1 : 0);

            $session->forceFill([
                'status' => $session->status === 'open' ? 'in_progress' : $session->status,
                'confirmed_parent_count' => $confirmedParents,
                'confirmed_child_count' => $confirmedChildren,
            ])->save();

            if ($session->isInboundAsn() && $targetLine->line_role === 'parent') {
                $parentEpc = Epc::query()->find($targetLine->epc_id);
                if ($parentEpc !== null) {
                    $seeded = $this->seedReceivingAsnParentChildren->handle(
                        $session,
                        $parentEpc,
                        $userId,
                        autoConfirmChildren: false,
                    );

                    $session->forceFill([
                        'expected_child_count' => $seeded['expected_child_count'],
                        'confirmed_child_count' => (int) $session->confirmed_child_count + $seeded['confirmed_children'],
                    ])->save();
                }
            }

            $needsCompletion = $this->markSessionCompletedIfReady($session->refresh());

            return [
                'ok' => true,
                'line' => $targetLine->fresh(),
                'message' => null,
                'needs_completion' => $needsCompletion,
            ];
        });
    }

    private function markSessionCompletedIfReady(ReceivingSession $session): bool
    {
        if (! $session->isInboundAsn()) {
            return false;
        }

        if (! TenantSettings::forTenant(tenant())->autoCompleteAsnOnReady()) {
            return false;
        }

        if (! $session->isReadyToCompleteInboundAsn()) {
            return false;
        }

        $session->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'active_parent_epc_id' => null,
        ])->save();

        return true;
    }

    private function isParentConfirmed(ReceivingSession $session, ReceivingScanLine $childLine): bool
    {
        if ($childLine->parent_epc_id === null) {
            return true;
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('epc_id', $childLine->parent_epc_id)
            ->where('line_role', 'parent')
            ->where('status', 'confirmed')
            ->exists();
    }

    private function overlayScanIdentity(
        ReceivingScanLine $targetLine,
        ReceivingScanLine $sourceLine,
        ?int $userId,
    ): void {
        $targetLine->forceFill([
            'scan_raw' => $sourceLine->scan_raw ?? $targetLine->scan_raw,
            'confirmed_at' => $sourceLine->confirmed_at ?? $targetLine->confirmed_at ?? now(),
            'confirmed_by' => $userId ?? $sourceLine->confirmed_by ?? $targetLine->confirmed_by,
            'ilmd_mismatch_json' => $sourceLine->ilmd_mismatch_json ?? $targetLine->ilmd_mismatch_json,
        ])->save();
    }
}
