<?php

namespace App\Actions\Disposition;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Disposition\DispositionScanLine;
use App\Models\Disposition\DispositionSession;
use App\Models\Epcis\Epc;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persist a staged disposition scan and reserve the EPC tenant-wide.
 */
final class StageDispositionScan
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
     *     line: ?DispositionScanLine,
     *     epc: ?Epc,
     *     blocking_session_type?: string,
     *     blocking_session_id?: int
     * }
     */
    public function handle(DispositionSession $session, string $scan, ?int $userId = null): array
    {
        if ($session->status !== 'open') {
            return [
                'ok' => false,
                'message' => 'This disposition session is already closed.',
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

        $existing = DispositionScanLine::query()
            ->where('disposition_session_id', $session->getKey())
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

        $block = $this->exclusiveGate->checkScannedEpc($epc, ExclusiveSessionContext::forDisposition($session));
        if ($block !== null) {
            return [
                ...$block->toScanResult(),
                'line' => null,
                'epc' => $epc,
            ];
        }

        try {
            return DB::transaction(function () use ($session, $scan, $epc): array {
                DispositionSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

                $line = DispositionScanLine::query()->updateOrCreate(
                    [
                        'disposition_session_id' => $session->getKey(),
                        'epc_id' => $epc->getKey(),
                    ],
                    [
                        'status' => 'staged',
                        'scan_raw' => $scan,
                    ],
                );

                $stagedCount = DispositionScanLine::query()
                    ->where('disposition_session_id', $session->getKey())
                    ->where('status', 'staged')
                    ->count();

                DispositionSession::query()->whereKey($session->getKey())->update([
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
