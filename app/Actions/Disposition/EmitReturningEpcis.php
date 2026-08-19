<?php

declare(strict_types=1);

namespace App\Actions\Disposition;

use App\Actions\Labeling\PersistAuthoredSsccEpcis;
use App\Actions\Outbound\GenerateDispositionEpcisDocument;
use App\Actions\Outbound\GenerateDispositionObjectEvent;
use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Receiving\ReceivingGate;
use App\Support\Disposition\AcquireReturningEpcLocks;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Shipping\EpcOnOpenShippingSession;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\Transferring\EpcOnOpenTransferringSession;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EmitReturningEpcis
{
    public function __construct(
        private readonly GenerateDispositionEpcisDocument $documentGenerator,
        private readonly PersistAuthoredSsccEpcis $persist,
        private readonly ReceivingGate $receivingGate,
        private readonly EpcCustodyGate $custodyGate,
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
        private readonly AcquireReturningEpcLocks $returnLocks,
        private readonly EpcOnOpenShippingSession $epcOnOpenShippingSession,
        private readonly EpcOnOpenTransferringSession $epcOnOpenTransferringSession,
        private readonly EpcOnAnotherOpenReceivingSession $epcOnAnotherOpenReceivingSession,
    ) {}

    /**
     * @param  list<int>  $epcIds
     * @param  array{sync?: bool, dispatch?: bool}  $options
     * @return array{
     *     document: EpcisDocument|null,
     *     returned_count: int,
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
                'returned_count' => 0,
                'path' => null,
            ];
        }

        $locks = $this->returnLocks->acquire($epcIds);

        try {
            return $this->emitWithinLock($epcIds, $siteId, $options);
        } finally {
            $this->returnLocks->release($locks);
        }
    }

    /**
     * @param  list<int>  $epcIds
     * @param  array{sync?: bool, dispatch?: bool}  $options
     * @return array{
     *     document: EpcisDocument|null,
     *     returned_count: int,
     *     path: string|null
     * }
     */
    private function emitWithinLock(array $epcIds, int $siteId, array $options): array
    {
        $notOnHand = [];
        foreach ($epcIds as $epcId) {
            if (! $this->shippableEpcsAtSite->contains($siteId, $epcId)) {
                $notOnHand[] = $epcId;
            }
        }

        if ($notOnHand !== []) {
            throw new InvalidArgumentException(
                'Cannot return — EPC(s) are not on hand at the selected site: '.
                implode(', ', array_map(fn (int $id): string => '#'.$id, $notOnHand)).'.',
            );
        }

        $epcs = Epc::query()
            ->whereIn('id', $epcIds)
            ->get()
            ->keyBy(fn (Epc $epc): int => (int) $epc->getKey());

        $uris = [];
        foreach ($epcIds as $epcId) {
            $epc = $epcs->get($epcId);
            if (! $epc instanceof Epc || blank($epc->epc_uri)) {
                throw new InvalidArgumentException("EPC #{$epcId} is missing an epc_uri for returning.");
            }

            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                throw new InvalidArgumentException(
                    'Cannot return — this unit is under an open quarantine hold.',
                );
            }

            if ($this->epcOnOpenShippingSession->exists($epc)) {
                throw new InvalidArgumentException(
                    'Cannot return — this unit is already confirmed on an open ship order.',
                );
            }

            if ($this->epcOnOpenTransferringSession->exists($epc)) {
                throw new InvalidArgumentException(
                    'Cannot return — this unit is already confirmed on an open or in-transit transfer.',
                );
            }

            if ($this->epcOnAnotherOpenReceivingSession->existsOnAnyExclusiveSession($epc)) {
                throw new InvalidArgumentException(
                    'Cannot return — this unit is already confirmed on an open receive session.',
                );
            }

            $uris[] = (string) $epc->epc_uri;
        }

        // Refuse terminal dispositions; returning expects operable stock at a tenant site.
        $this->custodyGate->assertInCustody($epcIds, 'returning');

        $xml = $this->documentGenerator->execute(
            $uris,
            GenerateDispositionObjectEvent::KIND_RETURNING,
            $siteId,
        );

        $uuid = (string) Str::uuid();
        $path = 'epcis/outbound/returning-'.$uuid.'.xml';

        $document = $this->persist->handle($xml, $path, [
            'authored_kind' => EpcisAuthoredKind::Returning,
            'original_filename' => 'returning-'.$uuid.'.xml',
            'notes' => 'Generated returning EPCIS for '.count($uris).' EPC(s).',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? true),
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        return [
            'document' => $document,
            'returned_count' => count($uris),
            'path' => $path,
        ];
    }
}
