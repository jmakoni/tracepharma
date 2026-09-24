<?php

declare(strict_types=1);

namespace App\Actions\Receiving;

use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use App\Support\Receiving\ReceiveExceptionTypes;
use App\Support\Receiving\ReceiveSessionExceptionQuery;
use DomainException;

/**
 * File-less scan-first "Complete to hold". Does not author sellable receiving EPCIS.
 *
 * Ingest gap: ProcessEpcisDocument does not match a later inbound file to a held
 * scan-first session or resolve LATE_FAILED_EPCIS. ConfirmReceivingScan can stamp
 * matched_epcis_document_id when the file is already ingested, then scanned.
 */
final class HoldFilelessScanFirstReceivingSession
{
    public function __construct(
        private readonly AuthorReceiveSessionException $authorReceiveSessionException,
        private readonly QuarantineService $quarantine,
    ) {}

    public function handle(ReceivingSession $session, ?int $actorId = null): ReceivingSession
    {
        $session = $session->fresh() ?? $session;

        if (! $session->canCompleteToHold()) {
            throw new DomainException(
                'Complete to hold is only for file-less scan-first when Complete is hard-blocked without inbound EPCIS.',
            );
        }

        $actor = $actorId !== null ? User::query()->find($actorId) : auth()->user();
        $actor = $actor instanceof User ? $actor : null;

        $epcIds = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->whereNotNull('epc_id')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $late = $this->authorReceiveSessionException->lateFailedEpcis(
            $session,
            'missing_inbound_epcis',
            $actor,
            $epcIds,
        );

        if ($epcIds !== []) {
            $this->quarantine->openForCase(
                $late,
                $epcIds,
                'Complete to hold — waiting for EPCIS',
                $actor,
                $session->document,
                [
                    'receiving_session_id' => (int) $session->getKey(),
                    'source' => 'complete_to_hold',
                ],
            );
        }

        $this->assertExceptionsStayOpen($session);

        $session->forceFill([
            'status' => 'held',
        ])->save();

        return $session->fresh() ?? $session;
    }

    private function assertExceptionsStayOpen(ReceivingSession $session): void
    {
        $late = ReceiveSessionExceptionQuery::openCases(
            $session,
            [ReceiveExceptionTypes::LATE_FAILED_EPCIS],
        )->first();

        if ($late === null) {
            throw new DomainException(
                'Complete to hold must leave LATE_FAILED_EPCIS open for Investigator.',
            );
        }
    }
}
