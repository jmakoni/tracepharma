<?php

declare(strict_types=1);

namespace App\Actions\Packing;

use App\Actions\Labeling\PersistAuthoredSsccEpcis;
use App\Actions\Outbound\ResolveSsccAuthoredLocation;
use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Epcis\Outbound\Xml12Writer;
use App\Support\Shipping\ShippableEpcsAtSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Author a single EPCIS TransformationEvent (input EPCs → output SGTINs).
 *
 * Prefer XML → PersistAuthoredSsccEpcis → ProcessEpcisDocument so inputEPC/outputEPC
 * roles and extension_json.transformation_id match inbound ingest shape.
 *
 * Inputs are validated as on-hand at the site; they are not auto-decommissioned —
 * CBV transforming/commissioning disposition applies via the event only.
 */
final class AuthorTransformationRepack
{
    public function __construct(
        private readonly PersistAuthoredSsccEpcis $persist,
        private readonly Xml12Writer $xml12Writer,
        private readonly ResolveSsccAuthoredLocation $resolveLocation,
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
        private readonly EpcCustodyGate $custodyGate,
    ) {}

    /**
     * @param  list<int>  $inputEpcIds
     * @param  list<string>  $outputUris  SGTIN Pure Identity URNs for outputs (created on ingest if missing)
     * @param  array{sync?: bool, dispatch?: bool}  $options
     * @return array{
     *     document: EpcisDocument,
     *     transformation_id: string,
     *     input_count: int,
     *     output_count: int,
     *     path: string,
     * }
     */
    public function handle(
        int $siteId,
        array $inputEpcIds,
        array $outputUris,
        array $options = [],
    ): array {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            throw new InvalidArgumentException("Site #{$siteId} was not found.");
        }

        $inputEpcIds = array_values(array_unique(array_filter(
            array_map(intval(...), $inputEpcIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($inputEpcIds === []) {
            throw new InvalidArgumentException('Select at least one input EPC for the transformation.');
        }

        $outputUris = array_values(array_unique(array_filter(
            array_map(static fn (mixed $uri): string => trim((string) $uri), $outputUris),
            fn (string $uri): bool => $uri !== '',
        )));

        if ($outputUris === []) {
            throw new InvalidArgumentException('Provide at least one output SGTIN URN.');
        }

        foreach ($outputUris as $uri) {
            if (! str_starts_with(strtolower($uri), 'urn:epc:id:sgtin:')) {
                throw new InvalidArgumentException("Output must be an SGTIN URN: {$uri}");
            }
        }

        $inputs = Epc::query()
            ->whereIn('id', $inputEpcIds)
            ->get()
            ->keyBy(fn (Epc $epc): int => (int) $epc->getKey());

        $inputUris = [];
        foreach ($inputEpcIds as $epcId) {
            $epc = $inputs->get($epcId);
            if (! $epc instanceof Epc || blank($epc->epc_uri)) {
                throw new InvalidArgumentException("Input EPC #{$epcId} is missing.");
            }

            if (! $this->shippableEpcsAtSite->contains($siteId, $epcId)) {
                throw new InvalidArgumentException(
                    "Cannot transform — EPC #{$epcId} is not on hand at the selected site.",
                );
            }

            $inputUris[] = (string) $epc->epc_uri;
        }

        $this->custodyGate->assertOperableFor($inputEpcIds, 'repack transform');

        $transformationId = 'urn:uuid:'.(string) Str::uuid();
        $location = $this->resolveLocation->handle($siteId);
        $xml = $this->buildXml($inputUris, $outputUris, $transformationId, $location['sgln_urn']);

        $uuid = (string) Str::uuid();
        $path = 'epcis/outbound/transformation-'.$uuid.'.xml';

        $document = $this->persist->handle($xml, $path, [
            'authored_kind' => EpcisAuthoredKind::Transformation,
            'original_filename' => 'transformation-'.$uuid.'.xml',
            'notes' => 'Generated TransformationEvent (repack) — '.count($inputUris).' input(s) → '.count($outputUris).' output(s).',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? true),
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        $event = EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->where('event_type', 'TransformationEvent')
            ->first();

        // Prefer ingest projection; if processing did not emit TransformationEvent rows
        // with both roles (hard-gate / schema edge cases), write the ProcessEpcisDocument shape directly.
        if (! $this->hasCompleteTransformationRoles($event)) {
            $document = $this->persistDirectRows(
                siteId: $siteId,
                inputEpcIds: $inputEpcIds,
                inputUris: $inputUris,
                outputUris: $outputUris,
                transformationId: $transformationId,
                gln: $location['gln'],
                path: $path,
                xml: $xml,
                existingDocument: $document,
            );
        } else {
            // Asset Trace only projects last-good documents; failed ingest with no
            // processed_at would hide the event even though roles exist.
            $this->ensureLastGoodProjection($document);
        }

        return [
            'document' => $document->refresh(),
            'transformation_id' => $transformationId,
            'input_count' => count($inputUris),
            'output_count' => count($outputUris),
            'path' => $path,
        ];
    }

    private function hasCompleteTransformationRoles(?EpcisEvent $event): bool
    {
        if ($event === null) {
            return false;
        }

        $eventId = (int) $event->getKey();

        return DB::table('event_epcs')
            ->where('event_id', $eventId)
            ->where('role', 'inputEPC')
            ->exists()
            && DB::table('event_epcs')
                ->where('event_id', $eventId)
                ->where('role', 'outputEPC')
                ->exists();
    }

    private function ensureLastGoodProjection(EpcisDocument $document): void
    {
        $status = (string) $document->status;

        // Never coerce error (or voided) into last-good — Asset Trace would hide a failed transform.
        if (in_array($status, ['error', 'voided'], true)) {
            return;
        }

        $ok = in_array($status, ['parsed', 'validated', 'received', 'generated'], true);

        if ($ok && $document->processed_at !== null) {
            return;
        }

        $document->forceFill([
            'status' => $ok ? $status : 'parsed',
            'processed_at' => $document->processed_at ?? now(),
        ])->save();
    }

    /**
     * @param  list<string>  $inputUris
     * @param  list<string>  $outputUris
     */
    private function buildXml(
        array $inputUris,
        array $outputUris,
        string $transformationId,
        string $sglnUrn,
    ): string {
        $eventTime = htmlspecialchars(now()->utc()->format('Y-m-d\TH:i:s.v\Z'), ENT_XML1);
        $transformationIdXml = htmlspecialchars($transformationId, ENT_XML1);
        $sglnXml = htmlspecialchars($sglnUrn, ENT_XML1);

        $inputsXml = '';
        foreach ($inputUris as $uri) {
            $inputsXml .= '                    <epc>'.htmlspecialchars($uri, ENT_XML1)."</epc>\n";
        }

        $outputsXml = '';
        foreach ($outputUris as $uri) {
            $outputsXml .= '                    <epc>'.htmlspecialchars($uri, ENT_XML1)."</epc>\n";
        }

        $eventXml = <<<XML
            <TransformationEvent>
                <eventTime>{$eventTime}</eventTime>
                <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
                <transformationID>{$transformationIdXml}</transformationID>
                <inputEPCList>
{$inputsXml}                </inputEPCList>
                <outputEPCList>
{$outputsXml}                </outputEPCList>
                <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
                <disposition>urn:epcglobal:cbv:disp:active</disposition>
                <readPoint><id>{$sglnXml}</id></readPoint>
                <bizLocation><id>{$sglnXml}</id></bizLocation>
            </TransformationEvent>
XML;

        return $this->xml12Writer->buildDocument(now()->toIso8601String(), $eventXml);
    }

    /**
     * @param  list<int>  $inputEpcIds
     * @param  list<string>  $inputUris
     * @param  list<string>  $outputUris
     */
    private function persistDirectRows(
        int $siteId,
        array $inputEpcIds,
        array $inputUris,
        array $outputUris,
        string $transformationId,
        string $gln,
        string $path,
        string $xml,
        ?EpcisDocument $existingDocument = null,
    ): EpcisDocument {
        return DB::transaction(function () use (
            $siteId,
            $inputEpcIds,
            $inputUris,
            $outputUris,
            $transformationId,
            $gln,
            $path,
            $xml,
            $existingDocument,
        ): EpcisDocument {
            $document = $existingDocument ?? EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => EpcisAuthoredKind::Transformation,
                'ship_from_site_id' => $siteId,
                'format' => 'xml',
                'original_filename' => basename($path),
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'notes' => 'Generated TransformationEvent (repack) — direct persist.',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => count($inputUris) + count($outputUris),
                'received_at' => now(),
                'processed_at' => now(),
            ]);

            if ($existingDocument !== null) {
                $document->forceFill([
                    'status' => 'parsed',
                    'processed_at' => now(),
                    'event_count' => max(1, (int) $document->event_count),
                    'epc_count' => count($inputUris) + count($outputUris),
                    'notes' => trim((string) $document->notes)."\nDirect TransformationEvent persist fallback.",
                ])->save();
            }

            // Reuse an incomplete ingest TransformationEvent when present so fallback
            // does not leave a second event on the same document without roles.
            $event = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->where('event_type', 'TransformationEvent')
                ->orderBy('id')
                ->first();

            if ($event instanceof EpcisEvent) {
                DB::table('event_epcs')->where('event_id', $event->getKey())->delete();
                $event->forceFill([
                    'event_time' => now(),
                    'record_time' => now(),
                    'event_timezone_offset' => '+00:00',
                    'action' => 'ADD',
                    'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                    'disposition' => 'urn:epcglobal:cbv:disp:active',
                    'read_point_gln' => $gln,
                    'biz_location_gln' => $gln,
                    'extension_json' => ['transformation_id' => $transformationId],
                ])->save();
            } else {
                $event = EpcisEvent::query()->create([
                    'document_id' => $document->getKey(),
                    'event_id' => 'urn:uuid:'.(string) Str::uuid(),
                    'event_type' => 'TransformationEvent',
                    'event_time' => now(),
                    'record_time' => now(),
                    'event_timezone_offset' => '+00:00',
                    'action' => 'ADD',
                    'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                    'disposition' => 'urn:epcglobal:cbv:disp:active',
                    'read_point_gln' => $gln,
                    'biz_location_gln' => $gln,
                    'extension_json' => ['transformation_id' => $transformationId],
                ]);
            }

            $rows = [];
            foreach ($inputEpcIds as $epcId) {
                $rows[] = [
                    'event_id' => $event->getKey(),
                    'epc_id' => $epcId,
                    'role' => 'inputEPC',
                    'quantity' => null,
                    'uom' => null,
                ];
            }

            foreach ($outputUris as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc === null) {
                    $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
                }

                $rows[] = [
                    'event_id' => $event->getKey(),
                    'epc_id' => (int) $epc->getKey(),
                    'role' => 'outputEPC',
                    'quantity' => null,
                    'uom' => null,
                ];
            }

            DB::table('event_epcs')->insert($rows);

            return $document->refresh();
        });
    }
}
