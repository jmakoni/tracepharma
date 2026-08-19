<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\EventParty;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Soft warning when SBDH Sender GLN disagrees with the shipping event source
 * owning_party GLN (both present). Does not overwrite trading_partner_id.
 */
final class RecordSbdhOwningPartyMismatch
{
    public const EXCEPTION_TYPE = 'sbdh_source_owning_party_mismatch';

    public function handle(EpcisDocument $document): ?EpcisException
    {
        $senderGln = $this->normalizeGln($document->sender_gln);
        if ($senderGln === null) {
            return null;
        }

        $sourceOwningPartyGln = $this->resolveSourceOwningPartyGln($document);
        if ($sourceOwningPartyGln === null) {
            return null;
        }

        if ($senderGln === $sourceOwningPartyGln) {
            return null;
        }

        $alreadyOpen = EpcisException::query()
            ->where('document_id', $document->getKey())
            ->where('exception_type', self::EXCEPTION_TYPE)
            ->where('status', 'open')
            ->exists();

        if ($alreadyOpen) {
            return null;
        }

        return EpcisException::query()->create([
            'document_id' => $document->getKey(),
            'exception_type' => self::EXCEPTION_TYPE,
            'severity' => 'warning',
            'description' => sprintf(
                'SBDH Sender GLN (%s) does not match shipping event source owning_party GLN (%s). The document header may be misfiled.',
                $senderGln,
                $sourceOwningPartyGln,
            ),
            'status' => 'open',
        ]);
    }

    private function resolveSourceOwningPartyGln(EpcisDocument $document): ?string
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

        return $this->extractSourceOwningPartyGln($shippingEvent->parties);
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
    private function extractSourceOwningPartyGln(Collection $parties): ?string
    {
        foreach ($parties as $party) {
            if ($party->party_role !== 'source') {
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
