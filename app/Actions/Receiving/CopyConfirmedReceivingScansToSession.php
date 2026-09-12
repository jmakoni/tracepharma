<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Services\Receiving\ReceivingGate;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use Illuminate\Support\Facades\DB;

/**
 * Copy floor-confirmed scan lines from one receiving session onto another
 * (e.g. scan-first → ASN after Match ASN), matching by epc_id.
 */
final class CopyConfirmedReceivingScansToSession
{
    public function __construct(
        private readonly ConfirmReceivingScan $confirmReceivingScan,
        private readonly ConfirmExpectedScanLineOnSession $confirmExpectedScanLineOnSession,
        private readonly CompleteReceivingSession $completeReceivingSession,
        private readonly ReceivingGate $receivingGate,
        private readonly EpcOnAnotherOpenReceivingSession $epcOnAnotherOpenReceivingSession,
    ) {}

    /**
     * @return array{
     *     copied: int,
     *     already_confirmed: int,
     *     skipped: int,
     *     notes: list<string>
     * }
     */
    public function handle(
        ReceivingSession $from,
        ReceivingSession $to,
        ?int $userId = null,
        bool $strictManifestOnly = false,
    ): array {
        $from = $from->fresh() ?? $from;
        $to = $to->fresh() ?? $to;

        if ((int) $from->getKey() === (int) $to->getKey()) {
            return [
                'copied' => 0,
                'already_confirmed' => 0,
                'skipped' => 0,
                'notes' => ['Source and target session are the same — nothing to copy.'],
            ];
        }

        $copied = 0;
        $alreadyConfirmed = 0;
        $skipped = 0;
        $notes = [];
        $needsCompletion = false;

        $base = ReceivingScanLine::query()
            ->where('receiving_session_id', $from->getKey())
            ->where('status', 'confirmed')
            ->whereNotNull('epc_id');

        foreach (['parent', 'non_parent'] as $pass) {
            $query = (clone $base)->with('epc')->orderBy('id');
            if ($pass === 'parent') {
                $query->where('line_role', 'parent');
            } else {
                $query->where(function ($q): void {
                    $q->where('line_role', '!=', 'parent')
                        ->orWhereNull('line_role');
                });
            }

            $query->chunkById(500, function ($lines) use (
                $from,
                $to,
                $userId,
                $strictManifestOnly,
                &$copied,
                &$alreadyConfirmed,
                &$skipped,
                &$notes,
                &$needsCompletion,
            ): void {
                foreach ($lines as $sourceLine) {
                    $result = $this->copyOne($sourceLine, $from, $to, $userId, $strictManifestOnly);

                    match ($result['outcome']) {
                        'copied' => $copied++,
                        'already_confirmed' => $alreadyConfirmed++,
                        default => $skipped++,
                    };

                    if ($result['note'] !== null) {
                        $notes[] = $result['note'];
                    }

                    if ($result['needs_completion'] ?? false) {
                        $needsCompletion = true;
                    }
                }
            });
        }

        if ($needsCompletion) {
            try {
                $this->completeReceivingSession->handle($to->fresh(), $userId);
            } catch (\Throwable $e) {
                $notes[] = 'Copied confirms, but ASN receiving could not be completed: '.$e->getMessage();
            }
        }

        return [
            'copied' => $copied,
            'already_confirmed' => $alreadyConfirmed,
            'skipped' => $skipped,
            'notes' => $notes,
        ];
    }

    /**
     * @return array{outcome: 'copied'|'already_confirmed'|'skipped', note: ?string, needs_completion?: bool}
     */
    public function copyConfirmedEpc(
        ReceivingSession $from,
        ReceivingSession $to,
        int $epcId,
        ?int $userId = null,
        bool $strictManifestOnly = true,
    ): array {
        $sourceLine = ReceivingScanLine::query()
            ->where('receiving_session_id', $from->getKey())
            ->where('epc_id', $epcId)
            ->where('status', 'confirmed')
            ->with('epc')
            ->first();

        if ($sourceLine === null) {
            return [
                'outcome' => 'skipped',
                'note' => "Skipped epc_id {$epcId}: no confirmed line on source session.",
            ];
        }

        return $this->copyOne($sourceLine, $from, $to, $userId, $strictManifestOnly);
    }

    /**
     * @return array{outcome: 'copied'|'already_confirmed'|'skipped', note: ?string, needs_completion?: bool}
     */
    private function copyOne(
        ReceivingScanLine $sourceLine,
        ReceivingSession $from,
        ReceivingSession $to,
        ?int $userId,
        bool $strictManifestOnly = false,
    ): array {
        $epc = $sourceLine->epc;
        $epcId = (int) $sourceLine->epc_id;

        if ($epc === null) {
            $epc = Epc::query()->find($epcId);
        }

        if ($epc === null) {
            return [
                'outcome' => 'skipped',
                'note' => "Skipped epc_id {$epcId}: EPC record missing.",
            ];
        }

        $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
        if ($hold !== null) {
            $caseId = $hold->exception_id;
            $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

            return [
                'outcome' => 'skipped',
                'note' => 'Under quarantine'.$suffix.'. Clear or release quarantine before receiving.',
            ];
        }

        $scan = filled($sourceLine->scan_raw)
            ? (string) $sourceLine->scan_raw
            : (string) $epc->epc_uri;

        $targetLine = ReceivingScanLine::query()
            ->where('receiving_session_id', $to->getKey())
            ->where('epc_id', $epcId)
            ->first();

        if ($targetLine !== null && $targetLine->status === 'confirmed') {
            // Transfer receive may need dual-write repair when the receiving line was
            // confirmed without ConfirmTransferringReceiveScan succeeding.
            if ($to->isTransferReceive() && ! $strictManifestOnly) {
                $confirm = $this->confirmReceivingScan->handle($to->fresh(), $scan, $userId);
                if ($confirm['ok'] || ($confirm['effect'] ?? null) === 'already_confirmed') {
                    return [
                        'outcome' => 'already_confirmed',
                        'note' => null,
                    ];
                }

                return [
                    'outcome' => 'skipped',
                    'note' => $confirm['message'] ?? "Skipped epc_id {$epcId}: transfer receive repair failed.",
                ];
            }

            return [
                'outcome' => 'already_confirmed',
                'note' => null,
            ];
        }

        if ($targetLine !== null && $targetLine->status === 'expected') {
            if ($strictManifestOnly) {
                $confirm = $this->confirmExpectedScanLineOnSession->handle($to->fresh(), $sourceLine, $userId);

                if ($confirm['ok']) {
                    return [
                        'outcome' => 'copied',
                        'note' => null,
                        'needs_completion' => (bool) ($confirm['needs_completion'] ?? false),
                    ];
                }

                return [
                    'outcome' => 'skipped',
                    'note' => $confirm['message'],
                ];
            }

            $confirm = $this->confirmReceivingScan->handle($to->fresh(), $scan, $userId);

            if ($confirm['ok']) {
                $this->overlayScanIdentity($confirm['line'] ?? $targetLine, $sourceLine, $userId);

                return [
                    'outcome' => 'copied',
                    'note' => null,
                ];
            }

            if (($confirm['effect'] ?? null) === 'quarantined') {
                return [
                    'outcome' => 'skipped',
                    'note' => $confirm['message'] ?? "Skipped epc_id {$epcId}: unit is under quarantine.",
                ];
            }

            // Transfer receive must dual-write transferring_scan_lines — never
            // fall through to a direct confirm that skips ConfirmTransferringReceiveScan.
            if ($to->isTransferReceive()) {
                return [
                    'outcome' => 'skipped',
                    'note' => $confirm['message'] ?? "Skipped epc_id {$epcId}: transfer receive confirm failed.",
                ];
            }

            return [
                'outcome' => 'skipped',
                'note' => $confirm['message'] ?? "Skipped epc_id {$epcId}: confirm failed.",
            ];
        }

        if ($targetLine !== null && $targetLine->status === 'unexpected') {
            if ($strictManifestOnly) {
                return [
                    'outcome' => 'skipped',
                    'note' => "Skipped epc_id {$epcId}: line is unexpected on target session.",
                ];
            }

            if ($to->isTransferReceive()) {
                return [
                    'outcome' => 'skipped',
                    'note' => "Skipped epc_id {$epcId}: transfer receive does not support off-manifest copy.",
                ];
            }

            return $this->markLineConfirmed($targetLine, $to, $sourceLine, $userId, $from);
        }

        if ($strictManifestOnly) {
            return [
                'outcome' => 'skipped',
                'note' => "Skipped epc_id {$epcId}: not on target session expected lines.",
            ];
        }

        if ($to->isTransferReceive()) {
            return [
                'outcome' => 'skipped',
                'note' => "Skipped epc_id {$epcId}: not on transfer receive expected lines.",
            ];
        }

        // Not on ASN seeded lines — ASN path supports unexpected; create confirmed-off-ASN line.
        return $this->createUnexpectedConfirmed($to, $epc, $sourceLine, $scan, $userId, $from);
    }

    private function epcBlockedByOtherOpenSession(
        Epc $epc,
        ReceivingSession $target,
        ReceivingSession $source,
    ): bool {
        if (! $this->epcOnAnotherOpenReceivingSession->exists($epc, $target)) {
            return false;
        }

        $other = $this->epcOnAnotherOpenReceivingSession->otherSession($epc, $target);

        if ($other === null) {
            return false;
        }

        if ((int) $other->getKey() === (int) $source->getKey()) {
            return false;
        }

        // Scan-first confirms are the intended source for transfer receive backfill.
        if ($target->isTransferReceive() && $other->isScanFirst()) {
            return false;
        }

        return true;
    }

    /**
     * @return array{outcome: 'copied'|'skipped', note: ?string}
     */
    private function markLineConfirmed(
        ReceivingScanLine $line,
        ReceivingSession $session,
        ReceivingScanLine $sourceLine,
        ?int $userId,
        ReceivingSession $sourceSession,
    ): array {
        return DB::transaction(function () use ($line, $session, $sourceLine, $userId, $sourceSession): array {
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $line = ReceivingScanLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            if ($line->status === 'confirmed') {
                return [
                    'outcome' => 'copied',
                    'note' => null,
                ];
            }

            $epc = $line->epc ?? Epc::query()->find($line->epc_id);
            if ($epc !== null) {
                $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
                if ($hold !== null) {
                    $caseId = $hold->exception_id;
                    $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

                    return [
                        'outcome' => 'skipped',
                        'note' => 'Under quarantine'.$suffix.'. Clear or release quarantine before receiving.',
                    ];
                }

                if ($this->epcBlockedByOtherOpenSession($epc, $session, $sourceSession)) {
                    return [
                        'outcome' => 'skipped',
                        'note' => 'Already confirmed on another open receive session.',
                    ];
                }
            }

            $line->forceFill([
                'status' => 'confirmed',
                'scan_raw' => $sourceLine->scan_raw ?? $line->scan_raw,
                'confirmed_at' => $sourceLine->confirmed_at ?? now(),
                'confirmed_by' => $userId ?? $sourceLine->confirmed_by,
                'ilmd_mismatch_json' => $sourceLine->ilmd_mismatch_json ?? $line->ilmd_mismatch_json,
            ])->save();

            $session->forceFill([
                'status' => $session->status === 'open' ? 'in_progress' : $session->status,
                'confirmed_parent_count' => (int) $session->confirmed_parent_count + ($line->line_role === 'parent' ? 1 : 0),
                'confirmed_child_count' => (int) $session->confirmed_child_count + ($line->line_role === 'child' ? 1 : 0),
            ])->save();

            return [
                'outcome' => 'copied',
                'note' => null,
            ];
        });
    }

    /**
     * @return array{outcome: 'copied'|'skipped', note: ?string}
     */
    private function createUnexpectedConfirmed(
        ReceivingSession $session,
        Epc $epc,
        ReceivingScanLine $sourceLine,
        string $scan,
        ?int $userId,
        ReceivingSession $sourceSession,
    ): array {
        return DB::transaction(function () use ($session, $epc, $sourceLine, $scan, $userId, $sourceSession): array {
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                $caseId = $hold->exception_id;
                $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

                return [
                    'outcome' => 'skipped',
                    'note' => 'Under quarantine'.$suffix.'. Clear or release quarantine before receiving.',
                ];
            }

            if ($this->epcBlockedByOtherOpenSession($epc, $session, $sourceSession)) {
                return [
                    'outcome' => 'skipped',
                    'note' => 'Already confirmed on another open receive session.',
                ];
            }

            $existing = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === 'confirmed') {
                    return [
                        'outcome' => 'copied',
                        'note' => null,
                    ];
                }

                $existing->forceFill([
                    'status' => 'confirmed',
                    'scan_raw' => $sourceLine->scan_raw ?? $existing->scan_raw ?? $scan,
                    'confirmed_at' => $sourceLine->confirmed_at ?? now(),
                    'confirmed_by' => $userId ?? $sourceLine->confirmed_by,
                    'ilmd_mismatch_json' => $sourceLine->ilmd_mismatch_json ?? $existing->ilmd_mismatch_json,
                ])->save();

                $session->forceFill([
                    'status' => $session->status === 'open' ? 'in_progress' : $session->status,
                    'confirmed_parent_count' => (int) $session->confirmed_parent_count + ($existing->line_role === 'parent' ? 1 : 0),
                    'confirmed_child_count' => (int) $session->confirmed_child_count + ($existing->line_role === 'child' ? 1 : 0),
                ])->save();

                return [
                    'outcome' => 'copied',
                    'note' => null,
                ];
            }

            $lineRole = $sourceLine->line_role
                ?: ($epc->epc_type === 'sscc' ? 'parent' : 'child');

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => $sourceLine->parent_epc_id,
                'line_role' => $lineRole,
                'status' => 'confirmed',
                'scan_raw' => $sourceLine->scan_raw ?? $scan,
                'confirmed_at' => $sourceLine->confirmed_at ?? now(),
                'confirmed_by' => $userId ?? $sourceLine->confirmed_by,
                'ilmd_mismatch_json' => $sourceLine->ilmd_mismatch_json,
            ]);

            $session->forceFill([
                'status' => $session->status === 'open' ? 'in_progress' : $session->status,
                'confirmed_parent_count' => (int) $session->confirmed_parent_count + ($lineRole === 'parent' ? 1 : 0),
                'confirmed_child_count' => (int) $session->confirmed_child_count + ($lineRole === 'child' ? 1 : 0),
            ])->save();

            return [
                'outcome' => 'copied',
                'note' => "EPC {$epc->getKey()} was not on ASN expected lines — created confirmed line (off-ASN).",
            ];
        });
    }

    private function overlayScanIdentity(
        ?ReceivingScanLine $targetLine,
        ReceivingScanLine $sourceLine,
        ?int $userId,
    ): void {
        if ($targetLine === null) {
            return;
        }

        $targetLine->forceFill([
            'scan_raw' => $sourceLine->scan_raw ?? $targetLine->scan_raw,
            'confirmed_at' => $sourceLine->confirmed_at ?? $targetLine->confirmed_at ?? now(),
            'confirmed_by' => $userId ?? $sourceLine->confirmed_by ?? $targetLine->confirmed_by,
            'ilmd_mismatch_json' => $sourceLine->ilmd_mismatch_json ?? $targetLine->ilmd_mismatch_json,
        ])->save();
    }
}
