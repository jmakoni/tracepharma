<?php

namespace App\Actions\Epcis;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Services\Exceptions\ExceptionService;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\Schema;

/**
 * Open an ERROR_DECLARATION case when inbound events carry errorDeclaration.
 * Void shipping already opens the same type on authored void documents.
 */
final class RecordInboundErrorDeclaration
{
    public function __construct(
        private readonly ExceptionService $exceptions,
    ) {}

    public function handle(EpcisDocument $document): void
    {
        if ((string) ($document->direction ?? '') !== 'inbound') {
            return;
        }

        $errorType = ExceptionType::query()->where('code', 'ERROR_DECLARATION')->first()
            ?? ExceptionTypeSeeder::ensure('ERROR_DECLARATION');
        if ($errorType === null) {
            return;
        }

        $events = $this->eventsWithErrorDeclaration($document);
        if ($events === []) {
            return;
        }

        $alreadyOpen = ExceptionCase::query()
            ->where('document_id', $document->getKey())
            ->where('exception_type_id', $errorType->getKey())
            ->get()
            ->contains(fn (ExceptionCase $case): bool => $case->status?->isOpen() === true);

        if ($alreadyOpen) {
            return;
        }

        $event = $events[0];
        $reason = trim((string) ($event->error_declaration['reason'] ?? ''));
        $epcIds = $event->epcs()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->exceptions->create([
            'exception_type_id' => $errorType->getKey(),
            'document_id' => $document->getKey(),
            'event_id' => $event->getKey(),
            'trading_partner_id' => $document->trading_partner_id,
            'site_id' => $document->ship_to_site_id ?? $document->ship_from_site_id,
            'title' => 'Error declaration',
            'description' => 'Inbound EPCIS event contains errorDeclaration.'
                .($reason !== '' ? ' Reason: '.$reason : ''),
            'severity' => ExceptionSeverity::Medium->value,
            'status' => ExceptionStatus::New->value,
        ], $epcIds, null, notify: false);
    }

    /**
     * @return list<EpcisEvent>
     */
    private function eventsWithErrorDeclaration(EpcisDocument $document): array
    {
        $query = EpcisEvent::query()->where('document_id', $document->getKey());

        if (Schema::hasColumn('epcis_events', 'ingest_generation') && $document->ingest_generation !== null) {
            $query->where('ingest_generation', $document->ingest_generation);
        }

        return $query
            ->get()
            ->filter(fn (EpcisEvent $event): bool => is_array($event->error_declaration) && $event->error_declaration !== [])
            ->values()
            ->all();
    }
}
