<?php

declare(strict_types=1);

namespace App\Actions\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Regenerate authored receiving EPCIS for completed ASN/scan-first sessions that
 * have confirmed lines but no usable receiving_epcis_document_id.
 */
final class RepairMissingReceivingEpcisEvents
{
    public function __construct(
        private readonly GenerateReceivingEpcisEvents $generate,
    ) {}

    /**
     * @return Collection<int, ReceivingSession>
     */
    public function candidates(?int $sessionId = null): Collection
    {
        return $this->candidateQuery($sessionId)
            ->orderBy('id')
            ->get()
            ->filter(fn (ReceivingSession $session): bool => $this->hasConfirmedLines($session))
            ->values();
    }

    /**
     * @return array{
     *     attempted: int,
     *     repaired: int,
     *     skipped: int,
     *     failed: int,
     *     results: list<array{session_id: int, status: string, document_id: ?int, message: ?string}>
     * }
     */
    public function handle(?int $sessionId = null, ?int $actorId = null, bool $dryRun = false): array
    {
        $results = [];
        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->candidates($sessionId) as $session) {
            if ($dryRun) {
                $skipped++;
                $results[] = [
                    'session_id' => (int) $session->getKey(),
                    'status' => 'dry_run',
                    'document_id' => null,
                    'message' => 'Would regenerate receiving EPCIS',
                ];

                continue;
            }

            try {
                $outcome = $this->generate->handle($session->fresh() ?? $session, $actorId, unpack: false);
                $session = $session->fresh() ?? $session;

                if ($outcome['generated'] && $session->receiving_epcis_document_id !== null) {
                    $repaired++;
                    $results[] = [
                        'session_id' => (int) $session->getKey(),
                        'status' => 'repaired',
                        'document_id' => (int) $session->receiving_epcis_document_id,
                        'message' => null,
                    ];
                } else {
                    $skipped++;
                    $results[] = [
                        'session_id' => (int) $session->getKey(),
                        'status' => 'skipped',
                        'document_id' => $session->receiving_epcis_document_id !== null
                            ? (int) $session->receiving_epcis_document_id
                            : null,
                        'message' => 'Generator did not create a new receiving document',
                    ];
                }
            } catch (Throwable $e) {
                $failed++;
                $results[] = [
                    'session_id' => (int) $session->getKey(),
                    'status' => 'failed',
                    'document_id' => null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'attempted' => count($results),
            'repaired' => $repaired,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * @return Builder<ReceivingSession>
     */
    private function candidateQuery(?int $sessionId = null): Builder
    {
        $query = ReceivingSession::query()
            ->where('status', 'completed')
            ->where(function (Builder $kinds): void {
                $kinds->whereNull('session_kind')
                    ->orWhereIn('session_kind', [
                        ReceivingSessionKind::InboundAsn->value,
                        ReceivingSessionKind::ScanFirst->value,
                    ]);
            })
            ->where(function (Builder $missingDoc): void {
                $missingDoc->whereNull('receiving_epcis_document_id')
                    ->orWhereDoesntHave('receivingDocument');
            });

        if ($sessionId !== null) {
            $query->whereKey($sessionId);
        }

        return $query;
    }

    private function hasConfirmedLines(ReceivingSession $session): bool
    {
        return ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->exists();
    }
}
