<?php

namespace App\Actions\Exceptions;

use App\Actions\Epcis\RecordDestinationGlnMismatch;
use App\Enums\ExceptionReceiveImpact;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionType;
use App\Services\Exceptions\ExceptionService;
use Database\Seeders\ExceptionTypeSeeder;

/**
 * Phase 2: when destination GLN mismatch blocks receive, elevate DESTINATION_*
 * exception types to BusinessRule and promote open ingest signals to cases.
 */
final class SyncDestinationGlnMismatchReceiveImpact
{
    /** @var list<string> */
    public const CODES = [
        RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
        RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
    ];

    public function __construct(
        private readonly ExceptionService $exceptions,
    ) {}

    public function handle(bool $blockReceive): void
    {
        $this->syncTypes($blockReceive);

        // Mass promote of all open DESTINATION_* signals removed: large tenants
        // hung under createFromSignal fan-out. Fresh signals promote lazily via
        // RecordDestinationGlnMismatch / ReceivingGate when the setting is on.
    }

    public function syncTypes(bool $blockReceive): void
    {
        foreach (self::CODES as $code) {
            ExceptionTypeSeeder::ensure($code);
        }

        $impact = $blockReceive
            ? ExceptionReceiveImpact::BusinessRule
            : ExceptionReceiveImpact::Warning;

        ExceptionType::query()
            ->whereIn('code', self::CODES)
            ->update(['receive_impact' => $impact->value]);
    }

    /**
     * Promote a freshly recorded DESTINATION_* signal when the tenant setting is on.
     */
    public function promoteIfBlocking(EpcisException $signal): void
    {
        if (! in_array($signal->exception_type, self::CODES, true)) {
            return;
        }

        // Types are elevated when the tenant setting is flipped; do not re-run
        // syncTypes here (locks exception_types under concurrent receive/settings).
        $this->exceptions->createFromSignal($signal);
    }
}
