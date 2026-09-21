<?php

declare(strict_types=1);

namespace App\Actions\Disposition;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Labeling\PersistAuthoredSsccEpcis;
use App\Actions\Outbound\GenerateDispositionEpcisDocument;
use App\Actions\Outbound\GenerateDispositionObjectEvent;
use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Support\Auth\CurrentSite;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class EmitDispensingEpcis
{
    public function __construct(
        private readonly GenerateDispositionEpcisDocument $documentGenerator,
        private readonly PersistAuthoredSsccEpcis $persist,
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
    ) {}

    /**
     * @param  list<int>  $epcIds
     * @param  array{sync?: bool, dispatch?: bool}  $options
     * @return array{document: EpcisDocument|null, dispensed_count: int, path: string|null}
     */
    public function handle(array $epcIds, int $siteId, array $options = []): array
    {
        $epcIds = array_values(array_unique(array_filter(
            array_map(intval(...), $epcIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($epcIds === [] || $siteId <= 0) {
            return ['document' => null, 'dispensed_count' => 0, 'path' => null];
        }

        $epcs = Epc::query()->whereIn('id', $epcIds)->get();
        $uris = [];
        foreach ($epcs as $epc) {
            if (filled($epc->epc_uri)) {
                $uris[] = (string) $epc->epc_uri;
            }
        }

        if ($uris === []) {
            throw new InvalidArgumentException('No EPC URIs available for dispensing EPCIS.');
        }

        $xml = $this->documentGenerator->execute(
            $uris,
            GenerateDispositionObjectEvent::KIND_DISPENSING,
            $siteId,
        );

        $uuid = (string) Str::uuid();
        $path = 'epcis/outbound/dispensing-'.$uuid.'.xml';

        $document = $this->persist->handle($xml, $path, [
            'authored_kind' => EpcisAuthoredKind::Dispensing,
            'original_filename' => 'dispensing-'.$uuid.'.xml',
            'notes' => 'Generated dispensing EPCIS for '.count($uris).' EPC(s).',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? true),
            'dispatch' => (bool) ($options['dispatch'] ?? false),
        ]);

        return [
            'document' => $document,
            'dispensed_count' => count($uris),
            'path' => $path,
        ];
    }

    public function maybeForVerifiedScan(string $scan, ?int $siteId = null): ?EpcisDocument
    {
        $siteId ??= CurrentSite::id();
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        try {
            $resolved = $this->resolveEpcFromScan->handle($scan);
        } catch (Throwable) {
            return null;
        }

        $epc = $resolved['epc'] ?? null;
        if (! $epc instanceof Epc) {
            return null;
        }

        return $this->handle([(int) $epc->getKey()], $siteId, ['sync' => true, 'dispatch' => false])['document'];
    }
}
