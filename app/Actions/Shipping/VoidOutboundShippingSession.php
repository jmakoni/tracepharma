<?php

namespace App\Actions\Shipping;

use App\Actions\Epcis\SyncDocumentEpcsFromEvents;
use App\Enums\EpcisAuthoredKind;
use App\Enums\EpcisGuideline;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Epcis\AuthoredEventTimezone;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\OutboundEpcisFilename;
use App\Support\Epcis\PersistEpcisXmlPayload;
use App\Support\Epcis\ResolveOutboundEpcisGuideline;
use App\Support\Epcis\SbdhInstanceIdentifier;
use App\Support\Epcis\ScheduleOutboundEpcisTransmission;
use App\Support\Epcis\ShippingTiTsFragments;
use App\Support\Gs1\Sgln;
use App\Support\TenantFeatures;
use Database\Seeders\ExceptionTypeSeeder;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Author a void_shipping document for a sent outbound session and hold the units.
 */
final class VoidOutboundShippingSession
{
    private const BIZ_STEP_VOID = 'urn:epcglobal:cbv:bizstep:void_shipping';

    private const BIZ_STEP_SHIPPING = 'urn:epcglobal:cbv:bizstep:shipping';

    private const DISPOSITION_ACTIVE = 'urn:epcglobal:cbv:disp:active';

    public function __construct(
        private readonly PersistEpcisXmlPayload $persistEpcisXmlPayload,
        private readonly ScheduleOutboundEpcisTransmission $scheduleOutboundTransmission,
        private readonly SyncDocumentEpcsFromEvents $syncDocumentEpcsFromEvents,
    ) {}

    public function handle(OutboundShippingSession $session, ?int $actorId = null): OutboundShippingSession
    {
        if (! TenantFeatures::forTenant(tenant())->canAuthorOutboundShipments()) {
            throw new DomainException('Outbound shipping is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User && $session->site_id !== null) {
            SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
        }

        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new DomainException('Cannot void a shipment outside tenant context.');
        }

        $voided = DB::transaction(function () use ($session, $actorId, $tenant): OutboundShippingSession {
            $session = OutboundShippingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (! $session->canVoid()) {
                throw new DomainException('This ship order cannot be voided.');
            }

            $original = EpcisDocument::query()->find($session->epcis_document_id);
            if ($original === null) {
                throw new DomainException('This ship order cannot be voided.');
            }

            $epcIds = OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->orderBy('id')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($epcIds === []) {
                throw new DomainException('This ship order cannot be voided.');
            }

            $epcs = Epc::query()->whereIn('id', $epcIds)->lockForUpdate()->get()->keyBy('id');
            $session->loadMissing('site', 'tradingPartner');
            $voidAt = now();
            $timezoneOffset = AuthoredEventTimezone::offsetForSite($session->site, $voidAt);
            $siteGln = Sgln::normalizeGln($session->site?->gln) ?? Sgln::normalizeGln($original->ship_from_gln);
            $guideline = ResolveOutboundEpcisGuideline::forPartner($session->tradingPartner);
            $voidUuid = (string) Str::uuid();
            $originalShipping = EpcisEvent::query()
                ->where('document_id', $original->getKey())
                ->where('biz_step', self::BIZ_STEP_SHIPPING)
                ->orderByDesc('event_time')
                ->first();

            $document = $this->createVoidDocument($session, $original, $tenant, $voidAt, $guideline);
            $event = $this->persistVoidEvent(
                $document,
                $session,
                $voidAt,
                $timezoneOffset,
                $voidUuid,
                $originalShipping,
                $siteGln,
            );
            $this->attachEpcs($event, $epcIds);
            $this->syncDocumentEpcsFromEvents->handle($document);

            $payload = $this->buildVoidXml(
                session: $session,
                original: $original,
                epcs: $epcs,
                voidAt: $voidAt,
                timezoneOffset: $timezoneOffset,
                voidUuid: $voidUuid,
                instanceId: (string) $document->document_uuid,
                guideline: $guideline,
                originalEventId: $originalShipping?->event_id,
                siteGln: $siteGln,
            );

            $this->persistEpcisXmlPayload->handle(
                $document,
                $payload,
                (string) $document->payload_path,
                (string) $document->payload_disk,
                'Void shipping EPCIS',
            );

            $document->forceFill([
                'event_count' => 1,
                'epc_count' => $epcs->count(),
                'status' => 'parsed',
                'processed_at' => $voidAt,
                'last_processed_at' => $voidAt,
            ])->save();

            foreach ($epcs as $epc) {
                $this->openVoidHold($epc, $document);
            }

            $this->openVoidExceptionCases($document, $epcIds, $event);

            $session->forceFill([
                'voided_at' => $voidAt,
                'voided_by_user_id' => $actorId,
                'void_epcis_document_id' => $document->getKey(),
            ])->save();

            return $session->fresh() ?? $session;
        });

        $voidDocument = EpcisDocument::query()->find($voided->void_epcis_document_id);
        if ($voidDocument instanceof EpcisDocument) {
            $this->scheduleOutboundTransmission->afterPersist($voidDocument, true);

            try {
                $this->assertOutboundHookDidNotFail($voidDocument);
            } catch (DomainException $e) {
                $this->undoFailedVoid($voided, $voidDocument);
                throw $e;
            }
        }

        return $voided;
    }

    private function createVoidDocument(
        OutboundShippingSession $session,
        EpcisDocument $original,
        Tenant $tenant,
        Carbon $voidAt,
        EpcisGuideline $guideline,
    ): EpcisDocument {
        $disk = (string) config('tracepharma.epcis.authored_payload_disk', 'local');
        $filename = OutboundEpcisFilename::forShippingEvent($tenant, $voidAt);
        $payloadPath = OutboundEpcisFilename::storagePath($tenant, $voidAt);

        $attributes = [
            'document_uuid' => SbdhInstanceIdentifier::uuid(),
            'schema_version' => EpcisSchemaVersion::V12,
            'creation_date' => $voidAt,
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Shipping,
            'trading_partner_id' => $session->trading_partner_id,
            'ship_to_partner_id' => $session->trading_partner_id,
            'sender_gln' => $original->sender_gln,
            'receiver_gln' => $original->receiver_gln,
            'format' => EpcisSchemaVersion::FORMAT_XML,
            'original_filename' => $filename,
            'payload_disk' => $disk,
            'payload_path' => $payloadPath,
            'dscsa_affirm' => (bool) $session->dscsa_affirm,
            'status' => 'generated',
            'notes' => 'Generated void shipping EPCIS for ship order session #'.$session->getKey().'.',
            'reprocess_count' => 0,
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => $voidAt,
            'ship_from_site_id' => $session->site_id,
            'ship_from_gln' => $original->ship_from_gln,
            'ship_to_site_id' => $original->ship_to_site_id,
            'ship_to_gln' => $original->ship_to_gln,
            'asn_number' => $session->asn_number,
            'customer_po' => $session->customer_po,
            'invoice_number' => $session->invoice_number,
        ];

        if (Schema::hasColumn('epcis_documents', 'dscsa_guideline_release')) {
            $attributes['dscsa_guideline_release'] = $guideline;
        }

        if ($session->outbound_connection_id !== null) {
            $attributes['outbound_connection_id'] = $session->outbound_connection_id;
        }

        if (Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            $attributes['ingest_generation'] = 1;
        }

        return EpcisDocument::query()->create($attributes);
    }

    private function persistVoidEvent(
        EpcisDocument $document,
        OutboundShippingSession $session,
        Carbon $voidAt,
        string $timezoneOffset,
        string $voidUuid,
        ?EpcisEvent $originalShipping,
        ?string $siteGln,
    ): EpcisEvent {
        $attributes = [
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.$voidUuid,
            'event_type' => 'ObjectEvent',
            'event_time' => $voidAt,
            'record_time' => $voidAt,
            'event_timezone_offset' => $timezoneOffset,
            'action' => 'OBSERVE',
            'biz_step' => self::BIZ_STEP_VOID,
            'disposition' => self::DISPOSITION_ACTIVE,
            'read_point_gln' => $siteGln,
            'biz_location_gln' => $siteGln,
            'trading_partner_id' => $session->trading_partner_id,
        ];

        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $attributes['ingest_generation'] = 1;
        }

        if (filled($originalShipping?->event_id) && Schema::hasColumn('epcis_events', 'error_declaration')) {
            $attributes['error_declaration'] = [
                'declarationTime' => $voidAt->clone()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'reason' => 'urn:epcglobal:cbv:er:incorrect_data',
                'correctiveEventIDs' => [(string) $originalShipping->event_id],
            ];
        }

        if (filled($originalShipping?->event_id) && Schema::hasColumn('epcis_events', 'corrective_event_ids')) {
            $attributes['corrective_event_ids'] = [(string) $originalShipping->event_id];
        }

        return EpcisEvent::query()->create($attributes);
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function attachEpcs(EpcisEvent $event, array $epcIds): void
    {
        if ($epcIds === [] || ! Schema::hasTable('event_epcs')) {
            return;
        }

        $rows = [];
        foreach ($epcIds as $epcId) {
            $rows[] = [
                'event_id' => $event->getKey(),
                'epc_id' => $epcId,
                'role' => 'epcList',
            ];
        }

        DB::table('event_epcs')->insert($rows);
    }

    /**
     * @param  Collection<int, Epc>  $epcs
     */
    private function buildVoidXml(
        OutboundShippingSession $session,
        EpcisDocument $original,
        $epcs,
        Carbon $voidAt,
        string $timezoneOffset,
        string $voidUuid,
        string $instanceId,
        EpcisGuideline $guideline,
        ?string $originalEventId,
        ?string $siteGln,
    ): string {
        $creationDate = $voidAt->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $eventTimeXml = $creationDate;
        $offsetXml = htmlspecialchars($timezoneOffset, ENT_XML1);
        $epcList = $epcs
            ->map(fn (Epc $epc): string => '          <epc>'.htmlspecialchars((string) $epc->epc_uri, ENT_XML1).'</epc>')
            ->implode("\n");
        $readPointUrn = $this->voidReadPointUrn($session, $siteGln);
        $readPointXml = $readPointUrn !== ''
            ? "        <readPoint>\n          <id>".htmlspecialchars($readPointUrn, ENT_XML1)."</id>\n        </readPoint>\n"
            : '';

        $errorDeclaration = '';
        if (filled($originalEventId)) {
            $errorDeclaration =
                "        <baseExtension>\n".
                "          <eventID>urn:uuid:{$voidUuid}</eventID>\n".
                "          <errorDeclaration>\n".
                "            <declarationTime>{$creationDate}</declarationTime>\n".
                "            <reason>urn:epcglobal:cbv:er:incorrect_data</reason>\n".
                "            <correctiveEventIDs>\n".
                '              <correctiveEventID>'.htmlspecialchars((string) $originalEventId, ENT_XML1)."</correctiveEventID>\n".
                "            </correctiveEventIDs>\n".
                "          </errorDeclaration>\n".
                "        </baseExtension>\n";
        } else {
            $errorDeclaration =
                "        <baseExtension>\n".
                "          <eventID>urn:uuid:{$voidUuid}</eventID>\n".
                "        </baseExtension>\n";
        }

        $header = '';
        $senderGln = (string) ($original->sender_gln ?? '');
        $receiverGln = (string) ($original->receiver_gln ?? '');
        if ($senderGln !== '' && $receiverGln !== '') {
            $header .= ShippingTiTsFragments::sbdhXml(
                senderGln: $senderGln,
                receiverGln: $receiverGln,
                instanceId: $instanceId,
                creationDate: $creationDate,
                guideline: $guideline,
            );
        }
        $header .= ShippingTiTsFragments::guidelineVersionXml($guideline);

        return
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".
            "<epcis:EPCISDocument\n".
            "    xmlns:epcis=\"urn:epcglobal:epcis:xsd:1\"\n".
            "    xmlns:sbdh=\"http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader\"\n".
            "    xmlns:gs1ushc=\"http://epcis.gs1us.org/hc/ns\"\n".
            "    schemaVersion=\"1.2\"\n".
            "    creationDate=\"{$creationDate}\">\n".
            "  <EPCISHeader>\n".
            $header.
            "  </EPCISHeader>\n".
            "  <EPCISBody>\n".
            "    <EventList>\n".
            "      <ObjectEvent>\n".
            "        <eventTime>{$eventTimeXml}</eventTime>\n".
            "        <recordTime>{$creationDate}</recordTime>\n".
            "        <eventTimeZoneOffset>{$offsetXml}</eventTimeZoneOffset>\n".
            $errorDeclaration.
            "        <epcList>\n".
            "{$epcList}\n".
            "        </epcList>\n".
            "        <action>OBSERVE</action>\n".
            '        <bizStep>'.self::BIZ_STEP_VOID."</bizStep>\n".
            '        <disposition>'.self::DISPOSITION_ACTIVE."</disposition>\n".
            $readPointXml.
            "      </ObjectEvent>\n".
            "    </EventList>\n".
            "  </EPCISBody>\n".
            "</epcis:EPCISDocument>\n";
    }

    private function voidReadPointUrn(OutboundShippingSession $session, ?string $siteGln): string
    {
        $siteUrn = trim((string) ($session->site?->sgln ?? ''));
        if ($siteUrn !== '' && str_starts_with(strtolower($siteUrn), 'urn:epc:id:sgln:')) {
            return $siteUrn;
        }

        return Sgln::resolveUrn($siteGln, $siteUrn !== '' ? $siteUrn : null) ?? '';
    }

    private function openVoidHold(Epc $epc, EpcisDocument $document): void
    {
        $existing = QuarantineHold::query()
            ->where('epc_id', $epc->getKey())
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return;
        }

        QuarantineHold::query()->create([
            'epc_id' => $epc->getKey(),
            'document_id' => $document->getKey(),
            'reason' => 'voided_shipment',
            'status' => 'open',
            'severity' => 'warning',
            'opened_at' => now(),
            'meta' => ['source' => 'void_shipping'],
        ]);
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function openVoidExceptionCases(EpcisDocument $document, array $epcIds, EpcisEvent $event): void
    {
        $exceptions = app(ExceptionService::class);

        $voidType = ExceptionType::query()->where('code', 'VOID_SHIPPING')->first()
            ?? ExceptionTypeSeeder::ensure('VOID_SHIPPING');
        if ($voidType !== null) {
            $exceptions->create([
                'exception_type_id' => $voidType->getKey(),
                'document_id' => $document->getKey(),
                'event_id' => $event->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => $document->ship_from_site_id,
                'title' => 'Void shipping',
                'description' => 'void_shipping document authored for outbound shipment. Units remain on quarantine hold until released.',
                'severity' => ExceptionSeverity::High->value,
                'status' => ExceptionStatus::New->value,
            ], $epcIds, null, notify: false);
        }

        $hasErrorDeclaration = is_array($event->error_declaration) && $event->error_declaration !== [];
        if (! $hasErrorDeclaration) {
            return;
        }

        $errorType = ExceptionType::query()->where('code', 'ERROR_DECLARATION')->first()
            ?? ExceptionTypeSeeder::ensure('ERROR_DECLARATION');
        if ($errorType === null) {
            return;
        }

        $exceptions->create([
            'exception_type_id' => $errorType->getKey(),
            'document_id' => $document->getKey(),
            'event_id' => $event->getKey(),
            'trading_partner_id' => $document->trading_partner_id,
            'site_id' => $document->ship_from_site_id,
            'title' => 'Error declaration',
            'description' => 'errorDeclaration written on void_shipping event (incorrect_data).',
            'severity' => ExceptionSeverity::Medium->value,
            'status' => ExceptionStatus::New->value,
        ], $epcIds, null, notify: false);
    }

    private function assertOutboundHookDidNotFail(EpcisDocument $document): void
    {
        $document = $document->fresh() ?? $document;
        $status = $document->transmission_status;

        if (! in_array($status, ['failed', 'skipped'], true)) {
            return;
        }

        if ($status === 'skipped' && in_array((string) $document->error_message, [
            'EPCIS payload path is empty.',
            'No active outbound connection.',
        ], true)) {
            return;
        }

        $detail = filled($document->error_message)
            ? (string) $document->error_message
            : $status;

        throw new DomainException(
            'Void shipping EPCIS was authored but outbound transmission did not succeed: '.$detail,
        );
    }

    private function undoFailedVoid(OutboundShippingSession $session, EpcisDocument $document): void
    {
        DB::transaction(function () use ($session, $document): void {
            $session = OutboundShippingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            QuarantineHold::query()
                ->where('document_id', $document->getKey())
                ->where('reason', 'voided_shipment')
                ->delete();

            ExceptionCase::query()
                ->where('document_id', $document->getKey())
                ->whereHas('type', fn ($query) => $query->whereIn('code', ['VOID_SHIPPING', 'ERROR_DECLARATION']))
                ->delete();

            $session->forceFill([
                'voided_at' => null,
                'voided_by_user_id' => null,
                'void_epcis_document_id' => null,
            ])->save();

            $document->forceFill(['status' => 'error'])->save();
        });
    }
}
