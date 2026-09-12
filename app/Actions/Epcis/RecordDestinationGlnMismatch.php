<?php

namespace App\Actions\Epcis;

use App\Actions\Exceptions\SyncDestinationGlnMismatchReceiveImpact;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\EventParty;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Support\Custody\TenantGlnSet;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Soft warning when inbound sold-to / ship-to GLNs are not in TenantGlnSet (ATTP-aligned).
 * Phase 2: optionally promote to receive-blocking cases when the tenant setting is on.
 */
final class RecordDestinationGlnMismatch
{
    public const OWNING_PARTY_EXCEPTION_TYPE = 'DESTINATION_OWNING_PARTY_MISMATCH';

    public const LOCATION_EXCEPTION_TYPE = 'DESTINATION_LOCATION_MISMATCH';

    public function __construct(
        private readonly RecordOperationalEpcisException $recorder,
        private readonly TenantGlnSet $tenantGlnSet = new TenantGlnSet,
    ) {}

    /**
     * @return list<EpcisException>
     */
    public function handle(EpcisDocument $document): array
    {
        if ((string) ($document->direction ?? '') !== 'inbound') {
            return [];
        }

        // Refresh soft signals so fixed master data / GLNs clear stale open rows.
        $this->clearOpenDestinationSignals($document);

        $features = TenantFeatures::forTenant(tenant());
        if (! $features->supportsReceiving()) {
            return [];
        }

        if ($this->tenantGlnSet->isEmpty()) {
            return [];
        }

        $soldToGln = $this->resolveSoldToGln($document);
        $shipToGln = $this->normalizeGln($document->ship_to_gln);

        $checkSoldTo = $this->shouldCheckSoldTo($features->profile());
        $checkShipTo = true;

        $created = [];

        $soldToMismatch = $checkSoldTo
            && $soldToGln !== null
            && ! $this->tenantGlnSet->contains($soldToGln);

        $shipToMismatch = $checkShipTo
            && $shipToGln !== null
            && ! $this->tenantGlnSet->contains($shipToGln);

        if ($soldToMismatch && $shipToMismatch && $soldToGln === $shipToGln) {
            $created[] = $this->recorder->handle(
                $document,
                self::OWNING_PARTY_EXCEPTION_TYPE,
                sprintf(
                    'Sold-to / destination owning party GLN (%s) is not one of this tenant\'s organization or facility GLNs.',
                    $soldToGln,
                ),
            );

            return $this->maybePromoteForReceiveBlock($created);
        }

        if ($soldToMismatch) {
            $created[] = $this->recorder->handle(
                $document,
                self::OWNING_PARTY_EXCEPTION_TYPE,
                sprintf(
                    'Sold-to / destination owning party GLN (%s) is not one of this tenant\'s organization or facility GLNs.',
                    $soldToGln,
                ),
            );
        }

        if ($shipToMismatch) {
            $created[] = $this->recorder->handle(
                $document,
                self::LOCATION_EXCEPTION_TYPE,
                sprintf(
                    'Ship-to / destination location GLN (%s) is not one of this tenant\'s organization or facility GLNs.',
                    $shipToGln,
                ),
            );
        }

        return $this->maybePromoteForReceiveBlock($created);
    }

    /**
     * @param  list<EpcisException>  $created
     * @return list<EpcisException>
     */
    private function maybePromoteForReceiveBlock(array $created): array
    {
        if ($created === []) {
            return $created;
        }

        if (! TenantSettings::forTenant(tenant())->blockReceiveOnDestinationGlnMismatch()) {
            return $created;
        }

        $sync = app(SyncDestinationGlnMismatchReceiveImpact::class);
        foreach ($created as $signal) {
            $sync->promoteIfBlocking($signal);
        }

        return $created;
    }

    private function clearOpenDestinationSignals(EpcisDocument $document): void
    {
        $codes = [
            self::OWNING_PARTY_EXCEPTION_TYPE,
            self::LOCATION_EXCEPTION_TYPE,
        ];

        $signals = EpcisException::query()
            ->where('document_id', $document->getKey())
            ->whereIn('exception_type', $codes)
            ->where('status', 'open')
            ->get(['id', 'case_id']);

        $caseIds = $signals
            ->pluck('case_id')
            ->filter(fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($signals->isNotEmpty()) {
            EpcisException::query()
                ->whereIn('id', $signals->pluck('id')->all())
                ->delete();
        }

        $typeIds = ExceptionType::query()
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $casesQuery = ExceptionCase::query()
            ->open()
            ->where('document_id', $document->getKey())
            ->whereDoesntHave('epcs');

        if ($caseIds !== [] && $typeIds !== []) {
            $casesQuery->where(function ($query) use ($caseIds, $typeIds): void {
                $query->whereIn('id', $caseIds)
                    ->orWhereIn('exception_type_id', $typeIds);
            });
        } elseif ($caseIds !== []) {
            $casesQuery->whereIn('id', $caseIds);
        } elseif ($typeIds !== []) {
            $casesQuery->whereIn('exception_type_id', $typeIds);
        } else {
            return;
        }

        foreach ($casesQuery->get() as $case) {
            // Destination mismatch auto-cleared — cancel so receive is not stuck.
            $case->forceFill(['status' => ExceptionStatus::Cancelled])->save();
        }
    }

    private function shouldCheckSoldTo(TenantProfile $profile): bool
    {
        return match ($profile) {
            TenantProfile::Logistics3pl => false,
            default => true,
        };
    }

    private function resolveSoldToGln(EpcisDocument $document): ?string
    {
        $fromEvent = $this->resolveDestinationOwningPartyGln($document);
        if ($fromEvent !== null) {
            return $fromEvent;
        }

        return $this->normalizeGln($document->receiver_gln);
    }

    private function resolveDestinationOwningPartyGln(EpcisDocument $document): ?string
    {
        $eventsQuery = Schema::hasColumn('epcis_events', 'ingest_generation')
            ? $document->activeEvents()
            : $document->events();

        $events = $eventsQuery
            ->with('parties')
            ->orderBy('id')
            ->get();

        $shippingEvent = $this->findBestEvent($events);
        if ($shippingEvent === null) {
            return null;
        }

        return $this->extractDestinationOwningPartyGln($shippingEvent->parties);
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     */
    private function findBestEvent(Collection $events): ?EpcisEvent
    {
        $shipping = $events->first(function (EpcisEvent $event): bool {
            if ($event->event_type !== 'ObjectEvent') {
                return false;
            }

            $bizStep = strtolower((string) ($event->biz_step ?? ''));

            return $bizStep !== '' && str_contains($bizStep, 'shipping');
        });

        if ($shipping !== null) {
            return $shipping;
        }

        return $events->first(function (EpcisEvent $event): bool {
            return $event->parties->contains(
                fn (EventParty $party): bool => in_array($party->party_role, ['source', 'destination'], true)
            );
        });
    }

    /**
     * @param  Collection<int, EventParty>  $parties
     */
    private function extractDestinationOwningPartyGln(Collection $parties): ?string
    {
        foreach ($parties as $party) {
            if ($party->party_role !== 'destination') {
                continue;
            }

            $extra = $party->extra_json;
            if (! is_array($extra)) {
                $extra = [];
            }

            if (strtolower((string) ($extra['source_dest_type'] ?? '')) !== 'owning_party') {
                continue;
            }

            $gln = $this->normalizeGln($party->gln);
            if ($gln !== null) {
                return $gln;
            }
        }

        return null;
    }

    private function normalizeGln(mixed $gln): ?string
    {
        if ($gln === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', (string) $gln) ?? '';

        return strlen($normalized) === 13 ? $normalized : (strlen($normalized) > 0 ? $normalized : null);
    }
}
