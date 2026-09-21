<?php

namespace App\Actions\Packing;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\Epc;
use App\Models\Packing\PackingScanLine;
use App\Models\Packing\PackingSession;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persist a staged pack scan and reserve the EPC tenant-wide.
 */
final class StagePackingScan
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly EpcExclusiveSessionGate $exclusiveGate,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     effect: string,
     *     line: ?PackingScanLine,
     *     epc: ?Epc,
     *     blocking_session_type?: string,
     *     blocking_session_id?: int
     * }
     */
    public function handle(
        PackingSession $session,
        string $scan,
        string $lineRole = 'child',
        ?int $userId = null,
    ): array {
        if ($session->status !== 'open') {
            return [
                'ok' => false,
                'message' => 'This pack session is already closed.',
                'effect' => 'not_in_session',
                'line' => null,
                'epc' => null,
            ];
        }

        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];

        if ($epc === null || blank($epc->epc_uri)) {
            return [
                'ok' => false,
                'message' => 'No EPC found for that scan.',
                'effect' => 'not_found',
                'line' => null,
                'epc' => null,
            ];
        }

        $existing = PackingScanLine::query()
            ->where('packing_session_id', $session->getKey())
            ->where('epc_id', $epc->getKey())
            ->first();

        if ($existing !== null && in_array($existing->status, ['staged', 'confirmed'], true)) {
            return [
                'ok' => true,
                'message' => $existing->status === 'staged' ? 'Scan already staged.' : 'Already confirmed.',
                'effect' => $existing->status === 'staged' ? 'already_staged' : 'already_confirmed',
                'line' => $existing,
                'epc' => $epc,
            ];
        }

        $block = $this->exclusiveGate->check($epc, ExclusiveSessionContext::forPacking($session));
        if ($block !== null) {
            return [
                ...$block->toScanResult(),
                'line' => null,
                'epc' => $epc,
            ];
        }

        try {
            return DB::transaction(function () use ($session, $scan, $epc, $lineRole, $userId): array {
                PackingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

                $line = PackingScanLine::query()->updateOrCreate(
                    [
                        'packing_session_id' => $session->getKey(),
                        'epc_id' => $epc->getKey(),
                    ],
                    [
                        'line_role' => $lineRole,
                        'status' => 'staged',
                        'scan_raw' => $scan,
                        'confirmed_at' => now(),
                        'confirmed_by' => $userId,
                    ],
                );

                $stagedCount = PackingScanLine::query()
                    ->where('packing_session_id', $session->getKey())
                    ->where('status', 'staged')
                    ->count();

                PackingSession::query()->whereKey($session->getKey())->update([
                    'staged_count' => $stagedCount,
                ]);

                return [
                    'ok' => true,
                    'message' => 'Staged.',
                    'effect' => 'staged',
                    'line' => $line->fresh(),
                    'epc' => $epc,
                ];
            });
        } catch (DomainException|InvalidArgumentException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'effect' => 'stage_failed',
                'line' => null,
                'epc' => $epc,
            ];
        }
    }
}
