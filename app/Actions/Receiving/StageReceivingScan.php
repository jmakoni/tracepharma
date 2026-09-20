<?php

namespace App\Actions\Receiving;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ResolveLotLevelReceiveScan;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persist a staged receive scan and reserve the EPC tenant-wide.
 */
final class StageReceivingScan
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
     *     line: ?ReceivingScanLine,
     *     epc: ?Epc,
     *     blocking_session_type?: string,
     *     blocking_session_id?: int
     * }
     */
    public function handle(ReceivingSession $session, string $scan, ?int $userId = null): array
    {
        if (! in_array($session->status, ['open', 'in_progress'], true)) {
            return [
                'ok' => false,
                'message' => 'This receiving session is already closed.',
                'effect' => 'not_in_session',
                'line' => null,
                'epc' => null,
            ];
        }

        if ($session->site_id === null && $session->isScanFirst()) {
            return [
                'ok' => false,
                'message' => 'Scan-first receive requires a site on this session.',
                'effect' => 'no_receive_site',
                'line' => null,
                'epc' => null,
            ];
        }

        try {
            $scan = app(ResolveLotLevelReceiveScan::class)->handle($session, $scan);
        } catch (DomainException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'effect' => 'scan_level',
                'line' => null,
                'epc' => null,
            ];
        }

        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];

        if ($epc === null) {
            return [
                'ok' => false,
                'message' => 'Unknown barcode — EPC must exist from prior EPCIS/commission.',
                'effect' => 'not_found',
                'line' => null,
                'epc' => null,
            ];
        }

        $policy = ReceivingPolicy::forTenant(tenant());
        $rejection = $policy->scanLevelRejectionMessage($epc, $session);
        if ($rejection !== null) {
            return [
                'ok' => false,
                'message' => $rejection,
                'effect' => 'scan_level',
                'line' => null,
                'epc' => $epc,
            ];
        }

        $existing = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('epc_id', $epc->getKey())
            ->first();

        if ($existing !== null) {
            if (in_array($existing->status, ['staged', 'confirmed', 'unexpected'], true)) {
                return [
                    'ok' => true,
                    'message' => $existing->status === 'staged' ? 'Scan already staged.' : 'Already confirmed.',
                    'effect' => $existing->status === 'staged' ? 'already_staged' : 'already_confirmed',
                    'line' => $existing,
                    'epc' => $epc,
                ];
            }
        }

        $block = $this->exclusiveGate->check($epc, ExclusiveSessionContext::forReceiving($session));
        if ($block !== null) {
            return [
                ...$block->toScanResult(),
                'line' => null,
                'epc' => $epc,
            ];
        }

        try {
            return DB::transaction(function () use ($session, $scan, $epc, $existing): array {
                $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

                if ($existing !== null && $existing->status === 'expected') {
                    $existing->forceFill([
                        'status' => 'staged',
                        'scan_raw' => $scan,
                    ])->save();
                    $line = $existing->fresh();
                } else {
                    $lineRole = $this->lineRole($epc);
                    $line = ReceivingScanLine::query()->create([
                        'receiving_session_id' => $session->getKey(),
                        'epc_id' => $epc->getKey(),
                        'parent_epc_id' => null,
                        'line_role' => $lineRole,
                        'status' => 'staged',
                        'scan_raw' => $scan,
                    ]);
                }

                return [
                    'ok' => true,
                    'message' => 'Staged.',
                    'effect' => 'staged',
                    'line' => $line,
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

    private function lineRole(Epc $epc): string
    {
        if ($epc->epc_type === 'sscc') {
            return 'parent';
        }

        return 'child';
    }
}
