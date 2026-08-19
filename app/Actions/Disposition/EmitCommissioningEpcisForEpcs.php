<?php

declare(strict_types=1);

namespace App\Actions\Disposition;

use App\Actions\Labeling\PersistAuthoredSsccEpcis;
use App\Actions\Outbound\GenerateDispositionEpcisDocument;
use App\Actions\Outbound\GenerateDispositionObjectEvent;
use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\SsccLabel;
use App\Services\Receiving\ReceivingGate;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Custody\TerminalEpcDisposition;
use App\Support\Disposition\AcquireCommissionEpcLocks;
use App\Support\Epcis\EpcHasCommissioningEvent;
use App\Support\Shipping\ShippableEpcsAtSite;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EmitCommissioningEpcisForEpcs
{
    public function __construct(
        private readonly GenerateDispositionEpcisDocument $documentGenerator,
        private readonly PersistAuthoredSsccEpcis $persist,
        private readonly EpcHasCommissioningEvent $hasCommissioningEvent,
        private readonly ResolveEpcLastKnownGln $lastKnownGln,
        private readonly ReceivingGate $receivingGate,
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
        private readonly AcquireCommissionEpcLocks $commissionLocks,
    ) {}

    /**
     * @param  list<int>  $epcIds
     * @param  array{sync?: bool, dispatch?: bool}  $options
     * @return array{
     *     document: EpcisDocument|null,
     *     commissioned_count: int,
     *     skipped_count: int,
     *     path: string|null
     * }
     */
    public function handle(array $epcIds, int $siteId, array $options = []): array
    {
        $epcIds = array_values(array_unique(array_filter(
            array_map(intval(...), $epcIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($epcIds === []) {
            return [
                'document' => null,
                'commissioned_count' => 0,
                'skipped_count' => 0,
                'path' => null,
            ];
        }

        $locks = $this->commissionLocks->acquire($epcIds);

        try {
            return $this->emitWithinLock($epcIds, $siteId, $options);
        } finally {
            $this->commissionLocks->release($locks);
        }
    }

    /**
     * @param  list<int>  $epcIds
     * @param  array{sync?: bool, dispatch?: bool}  $options
     * @return array{
     *     document: EpcisDocument|null,
     *     commissioned_count: int,
     *     skipped_count: int,
     *     path: string|null
     * }
     */
    private function emitWithinLock(array $epcIds, int $siteId, array $options): array
    {
        $already = $this->hasCommissioningEvent->among($epcIds);
        $alreadySet = array_fill_keys($already, true);
        $candidates = array_values(array_filter(
            $epcIds,
            fn (int $id): bool => ! isset($alreadySet[$id]),
        ));
        $skippedCount = count($epcIds) - count($candidates);

        if ($candidates === []) {
            return [
                'document' => null,
                'commissioned_count' => 0,
                'skipped_count' => $skippedCount,
                'path' => null,
            ];
        }

        $notOnHand = [];
        foreach ($candidates as $epcId) {
            if (! $this->shippableEpcsAtSite->contains($siteId, $epcId)) {
                $notOnHand[] = $epcId;
            }
        }

        if ($notOnHand !== []) {
            throw new InvalidArgumentException(
                'Cannot commission — EPC(s) are not on hand at the selected site: '.
                implode(', ', array_map(fn (int $id): string => '#'.$id, $notOnHand)).'.',
            );
        }

        $epcs = Epc::query()
            ->whereIn('id', $candidates)
            ->get()
            ->keyBy(fn (Epc $epc): int => (int) $epc->getKey());

        $uris = [];
        foreach ($candidates as $epcId) {
            $epc = $epcs->get($epcId);
            if (! $epc instanceof Epc || blank($epc->epc_uri)) {
                throw new InvalidArgumentException("EPC #{$epcId} is missing an epc_uri for commissioning.");
            }

            $meta = $this->lastKnownGln->latestEventMeta($epc);
            if (TerminalEpcDisposition::matches($meta)) {
                throw new InvalidArgumentException(
                    'Cannot commission — the latest event records this unit as '.
                    TerminalEpcDisposition::label($meta['disposition'] ?? null).'.',
                );
            }

            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                throw new InvalidArgumentException(
                    'Cannot commission — this unit is under an open quarantine hold.',
                );
            }

            $uris[] = (string) $epc->epc_uri;
        }

        $xml = $this->documentGenerator->execute(
            $uris,
            GenerateDispositionObjectEvent::KIND_COMMISSIONING,
            $siteId,
        );

        $uuid = (string) Str::uuid();
        $path = 'epcis/outbound/commission-all-'.$uuid.'.xml';
        $sync = (bool) ($options['sync'] ?? true);

        $document = $this->persist->handle($xml, $path, [
            'authored_kind' => EpcisAuthoredKind::Commissioning,
            'original_filename' => 'commission-all-'.$uuid.'.xml',
            'notes' => 'Generated commissioning EPCIS for '.count($uris).' EPC(s).',
            'ship_from_site_id' => $siteId,
            'sync' => $sync,
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        if ($sync) {
            $now = now();
            SsccLabel::query()
                ->whereIn('sscc_urn', $uris)
                ->whereNull('commissioned_at')
                ->update([
                    'commissioning_epcis_file_path' => $path,
                    'commissioned_at' => $now,
                ]);
        }

        return [
            'document' => $document,
            'commissioned_count' => count($uris),
            'skipped_count' => $skippedCount,
            'path' => $path,
        ];
    }
}
