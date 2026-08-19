<?php

namespace App\Actions\Demo;

use App\Actions\Disposition\EmitReturningEpcis;
use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Labeling\GenerateSsccLabelBatch;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\UnpackReceivingHierarchy;
use App\Actions\Shipping\CompleteOutboundShippingSession;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringReceiveScan;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\EpcisAuthoredKind;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\SsccLabelBatch;
use App\Models\TradingPartner;
use App\Models\Transferring\TransferringSession;
use App\Support\Gs1\Gtin;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;

/**
 * Idempotent receive→ship demo choreography for wholesaler/pharmacy ICP demos.
 *
 * Requires {@see SeedMasterData} (org HQ site, partners, outbound connection) first.
 */
final class SeedOperationalChoreography
{
    public const DEMO_RECEIVE_FILENAME = 'demo-choreography-receive.xml';

    public const DEMO_DOCUMENT_UUID = 'aaaaaaaa-bbbb-cccc-dddd-001demochoreo';

    public const DEMO_SHIP_ASN = 'DEMO-CHOREO-ASN-001';

    public const DEMO_SHIP_PO = 'DEMO-CHOREO-PO-001';

    public const DEMO_SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    public const DEMO_TRANSFER_RECEIVE_FILENAME = 'demo-choreography-transfer-receive.xml';

    public const DEMO_TRANSFER_DOCUMENT_UUID = 'aaaaaaaa-bbbb-cccc-dddd-002demochoreo';

    public const DEMO_TRANSFER_SSCC_URI = 'urn:epc:id:sscc:030116.01001227053';

    public const DEMO_TRANSFER_SESSION_NOTES = 'Demo choreography inter-site transfer (tracepharma:seed-demo-choreography --transfer)';

    public const DEMO_BRANCH_SITE_CODE = 'ORG-BRANCH';

    public const DEMO_HIERARCHY_RECEIVE_FILENAME = 'demo-choreography-hierarchy-receive.xml';

    public const DEMO_HIERARCHY_DOCUMENT_UUID = 'aaaaaaaa-bbbb-cccc-dddd-003demochoreo';

    public const DEMO_HIERARCHY_SSCC_URI = 'urn:epc:id:sscc:030116.01001227054';

    public const DEMO_HIERARCHY_CHILD_SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    public const DEMO_PACK_BATCH_NOTES = 'Demo choreography pack (tracepharma:seed-demo-choreography --pack)';

    public const DEMO_RETURN_DOCUMENT_NOTES = 'Demo choreography return (tracepharma:seed-demo-choreography --return)';

    public const DOWNSTREAM_PHARMACY_GLN = '0614141000005';

    /**
     * @return array{
     *     receive_session_id: int,
     *     receive_created: bool,
     *     ship_session_id: ?int,
     *     ship_created: bool,
     *     ship_completed: bool,
     *     ship_deferred: bool,
     *     ship_deferred_reason: ?string,
     *     transfer_receive_session_id: ?int,
     *     transfer_receive_created: bool,
     *     transfer_session_id: ?int,
     *     transfer_created: bool,
     *     transfer_completed: bool,
     *     transfer_deferred: bool,
     *     transfer_deferred_reason: ?string,
     *     hierarchy_receive_session_id: ?int,
     *     hierarchy_receive_created: bool,
     *     unpack_completed: bool,
     *     unpack_created: bool,
     *     unpack_deferred: bool,
     *     unpack_deferred_reason: ?string,
     *     pack_batch_id: ?int,
     *     pack_completed: bool,
     *     pack_created: bool,
     *     pack_deferred: bool,
     *     pack_deferred_reason: ?string,
     *     return_document_id: ?int,
     *     return_completed: bool,
     *     return_created: bool,
     *     return_deferred: bool,
     *     return_deferred_reason: ?string
     * }
     */
    public function handle(
        bool $completeShip = true,
        bool $includeTransfer = false,
        bool $completeTransfer = true,
        bool $includeUnpack = false,
        bool $includePack = false,
        bool $includeReturn = false,
    ): array {
        $site = $this->resolveOrganizationSite();

        [$receiveSession, $receiveCreated] = $this->ensureCompletedDemoReceive($site);

        $shipResult = $this->ensureDemoShipOrder($site, $completeShip);

        $transferResult = $includeTransfer
            ? $this->ensureDemoTransferPath($site, $completeTransfer)
            : $this->emptyTransferResult();

        $hierarchyResult = ($includeUnpack || $includePack || $includeReturn)
            ? $this->ensureDemoHierarchyPath(
                $site,
                includeUnpack: $includeUnpack || $includePack,
                includePack: $includePack,
                includeReturn: $includeReturn,
            )
            : $this->emptyHierarchyResult();

        return [
            'receive_session_id' => (int) $receiveSession->getKey(),
            'receive_created' => $receiveCreated,
            'ship_session_id' => $shipResult['session']?->getKey() !== null
                ? (int) $shipResult['session']->getKey()
                : null,
            'ship_created' => $shipResult['created'],
            'ship_completed' => $shipResult['completed'],
            'ship_deferred' => $shipResult['deferred'],
            'ship_deferred_reason' => $shipResult['deferred_reason'],
            'transfer_receive_session_id' => $transferResult['receive_session']?->getKey() !== null
                ? (int) $transferResult['receive_session']->getKey()
                : null,
            'transfer_receive_created' => $transferResult['receive_created'],
            'transfer_session_id' => $transferResult['session']?->getKey() !== null
                ? (int) $transferResult['session']->getKey()
                : null,
            'transfer_created' => $transferResult['created'],
            'transfer_completed' => $transferResult['completed'],
            'transfer_deferred' => $transferResult['deferred'],
            'transfer_deferred_reason' => $transferResult['deferred_reason'],
            'hierarchy_receive_session_id' => $hierarchyResult['receive_session']?->getKey() !== null
                ? (int) $hierarchyResult['receive_session']->getKey()
                : null,
            'hierarchy_receive_created' => $hierarchyResult['receive_created'],
            'unpack_completed' => $hierarchyResult['unpack_completed'],
            'unpack_created' => $hierarchyResult['unpack_created'],
            'unpack_deferred' => $hierarchyResult['unpack_deferred'],
            'unpack_deferred_reason' => $hierarchyResult['unpack_deferred_reason'],
            'pack_batch_id' => $hierarchyResult['pack_batch']?->getKey() !== null
                ? (int) $hierarchyResult['pack_batch']->getKey()
                : null,
            'pack_completed' => $hierarchyResult['pack_completed'],
            'pack_created' => $hierarchyResult['pack_created'],
            'pack_deferred' => $hierarchyResult['pack_deferred'],
            'pack_deferred_reason' => $hierarchyResult['pack_deferred_reason'],
            'return_document_id' => $hierarchyResult['return_document']?->getKey() !== null
                ? (int) $hierarchyResult['return_document']->getKey()
                : null,
            'return_completed' => $hierarchyResult['return_completed'],
            'return_created' => $hierarchyResult['return_created'],
            'return_deferred' => $hierarchyResult['return_deferred'],
            'return_deferred_reason' => $hierarchyResult['return_deferred_reason'],
        ];
    }

    /**
     * @return array{
     *     receive_session: ?ReceivingSession,
     *     receive_created: bool,
     *     session: ?TransferringSession,
     *     created: bool,
     *     completed: bool,
     *     deferred: bool,
     *     deferred_reason: ?string
     * }
     */
    private function emptyTransferResult(): array
    {
        return [
            'receive_session' => null,
            'receive_created' => false,
            'session' => null,
            'created' => false,
            'completed' => false,
            'deferred' => false,
            'deferred_reason' => null,
        ];
    }

    /**
     * @return array{
     *     receive_session: ?ReceivingSession,
     *     receive_created: bool,
     *     unpack_completed: bool,
     *     unpack_created: bool,
     *     unpack_deferred: bool,
     *     unpack_deferred_reason: ?string,
     *     pack_batch: ?SsccLabelBatch,
     *     pack_completed: bool,
     *     pack_created: bool,
     *     pack_deferred: bool,
     *     pack_deferred_reason: ?string,
     *     return_document: ?EpcisDocument,
     *     return_completed: bool,
     *     return_created: bool,
     *     return_deferred: bool,
     *     return_deferred_reason: ?string
     * }
     */
    private function emptyHierarchyResult(): array
    {
        return [
            'receive_session' => null,
            'receive_created' => false,
            'unpack_completed' => false,
            'unpack_created' => false,
            'unpack_deferred' => false,
            'unpack_deferred_reason' => null,
            'pack_batch' => null,
            'pack_completed' => false,
            'pack_created' => false,
            'pack_deferred' => false,
            'pack_deferred_reason' => null,
            'return_document' => null,
            'return_completed' => false,
            'return_created' => false,
            'return_deferred' => false,
            'return_deferred_reason' => null,
        ];
    }

    /**
     * Secondary hierarchy SSCC for unpack/pack/return demos without touching the primary ship SSCC.
     *
     * @return array{
     *     receive_session: ?ReceivingSession,
     *     receive_created: bool,
     *     unpack_completed: bool,
     *     unpack_created: bool,
     *     unpack_deferred: bool,
     *     unpack_deferred_reason: ?string,
     *     pack_batch: ?SsccLabelBatch,
     *     pack_completed: bool,
     *     pack_created: bool,
     *     pack_deferred: bool,
     *     pack_deferred_reason: ?string,
     *     return_document: ?EpcisDocument,
     *     return_completed: bool,
     *     return_created: bool,
     *     return_deferred: bool,
     *     return_deferred_reason: ?string
     * }
     */
    private function ensureDemoHierarchyPath(
        Site $site,
        bool $includeUnpack,
        bool $includePack,
        bool $includeReturn,
    ): array {
        $features = TenantFeatures::forTenant(tenant());

        if ($includeUnpack && ! $features->supportsUnpacking()) {
            return [
                ...$this->emptyHierarchyResult(),
                'unpack_deferred' => true,
                'unpack_deferred_reason' => 'Unpacking is not available for this tenant profile.',
            ];
        }

        if ($includePack && ! $features->supportsPacking()) {
            return [
                ...$this->emptyHierarchyResult(),
                'pack_deferred' => true,
                'pack_deferred_reason' => 'Packing is not available for this tenant profile.',
            ];
        }

        if ($includeReturn && ! $features->supportsReturning()) {
            return [
                ...$this->emptyHierarchyResult(),
                'return_deferred' => true,
                'return_deferred_reason' => 'Returning is not available for this tenant profile.',
            ];
        }

        [$receiveSession, $receiveCreated] = $this->ensureCompletedInboundReceive(
            $site,
            ssccUri: self::DEMO_HIERARCHY_SSCC_URI,
            receiveFilename: self::DEMO_HIERARCHY_RECEIVE_FILENAME,
            documentUuid: self::DEMO_HIERARCHY_DOCUMENT_UUID,
        );

        $result = [
            ...$this->emptyHierarchyResult(),
            'receive_session' => $receiveSession,
            'receive_created' => $receiveCreated,
        ];

        if ($includeUnpack) {
            $unpack = $this->ensureDemoUnpack($receiveSession);
            $result = [...$result, ...$unpack];
        }

        if ($includePack) {
            if ($includeUnpack && ! $result['unpack_completed']) {
                $result['pack_deferred'] = true;
                $result['pack_deferred_reason'] = $result['unpack_deferred_reason']
                    ?? 'Demo hierarchy must be unpacked before packing.';
            } else {
                if (! $includeUnpack) {
                    $unpack = $this->ensureDemoUnpack($receiveSession);
                    $result = [...$result, ...$unpack];
                }

                if ($result['unpack_completed']) {
                    $pack = $this->ensureDemoPack($site);
                    $result = [...$result, ...$pack];
                } else {
                    $result['pack_deferred'] = true;
                    $result['pack_deferred_reason'] = $result['unpack_deferred_reason']
                        ?? 'Demo hierarchy unpack did not complete; pack was skipped.';
                }
            }
        }

        if ($includeReturn) {
            $return = $this->ensureDemoReturn(
                $site,
                unpacked: (bool) $result['unpack_completed'],
                packed: (bool) $result['pack_completed'],
                packBatch: $result['pack_batch'],
            );
            $result = [...$result, ...$return];
        }

        return $result;
    }

    /**
     * @return array{
     *     unpack_completed: bool,
     *     unpack_created: bool,
     *     unpack_deferred: bool,
     *     unpack_deferred_reason: ?string
     * }
     */
    private function ensureDemoUnpack(ReceivingSession $session): array
    {
        $parentId = (int) (Epc::query()->where('epc_uri', self::DEMO_HIERARCHY_SSCC_URI)->value('id') ?? 0);

        if ($parentId <= 0) {
            return [
                'unpack_completed' => false,
                'unpack_created' => false,
                'unpack_deferred' => true,
                'unpack_deferred_reason' => 'Demo hierarchy SSCC was not ingested.',
            ];
        }

        $alreadyUnpacked = AggregationLink::query()
            ->where('parent_epc_id', $parentId)
            ->whereNotNull('valid_to')
            ->exists();

        if ($alreadyUnpacked) {
            return [
                'unpack_completed' => true,
                'unpack_created' => false,
                'unpack_deferred' => false,
                'unpack_deferred_reason' => null,
            ];
        }

        try {
            $unpacked = app(UnpackReceivingHierarchy::class)->handle($session->fresh());
        } catch (DomainException $exception) {
            return [
                'unpack_completed' => false,
                'unpack_created' => false,
                'unpack_deferred' => true,
                'unpack_deferred_reason' => 'Demo hierarchy unpack failed: '.$exception->getMessage(),
            ];
        }

        if (! $unpacked['generated']) {
            return [
                'unpack_completed' => false,
                'unpack_created' => false,
                'unpack_deferred' => true,
                'unpack_deferred_reason' => 'Demo hierarchy had no open aggregation links to unpack.',
            ];
        }

        return [
            'unpack_completed' => true,
            'unpack_created' => true,
            'unpack_deferred' => false,
            'unpack_deferred_reason' => null,
        ];
    }

    /**
     * @return array{
     *     pack_batch: ?SsccLabelBatch,
     *     pack_completed: bool,
     *     pack_created: bool,
     *     pack_deferred: bool,
     *     pack_deferred_reason: ?string
     * }
     */
    private function ensureDemoPack(Site $site): array
    {
        $existing = SsccLabelBatch::query()
            ->where('notes', self::DEMO_PACK_BATCH_NOTES)
            ->where('status', SsccLabelBatchStatus::Completed->value)
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return [
                'pack_batch' => $existing,
                'pack_completed' => true,
                'pack_created' => false,
                'pack_deferred' => false,
                'pack_deferred_reason' => null,
            ];
        }

        $childUri = (string) (Epc::query()
            ->where('epc_uri', self::DEMO_HIERARCHY_CHILD_SGTIN_URI)
            ->value('epc_uri') ?? '');

        if ($childUri === '') {
            return [
                'pack_batch' => null,
                'pack_completed' => false,
                'pack_created' => false,
                'pack_deferred' => true,
                'pack_deferred_reason' => 'Demo hierarchy child SGTIN was not found after unpack.',
            ];
        }

        try {
            $batch = app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'enforce_forward_only' => true,
                'site_id' => (int) $site->getKey(),
                'child_epcs' => $childUri,
                'emit_epcis' => true,
                'epcis_sync' => true,
                'emit_disaggregation' => false,
                'send_to_printer' => false,
                'notes' => self::DEMO_PACK_BATCH_NOTES,
            ]);
        } catch (DomainException|\InvalidArgumentException $exception) {
            return [
                'pack_batch' => null,
                'pack_completed' => false,
                'pack_created' => false,
                'pack_deferred' => true,
                'pack_deferred_reason' => 'Demo pack failed: '.$exception->getMessage(),
            ];
        }

        if ($batch->status !== SsccLabelBatchStatus::Completed || $batch->hasCommissioningError()) {
            return [
                'pack_batch' => $batch,
                'pack_completed' => false,
                'pack_created' => true,
                'pack_deferred' => true,
                'pack_deferred_reason' => 'Demo SSCC batch #'.$batch->getKey().' was not commissioned.',
            ];
        }

        return [
            'pack_batch' => $batch,
            'pack_completed' => true,
            'pack_created' => true,
            'pack_deferred' => false,
            'pack_deferred_reason' => null,
        ];
    }

    /**
     * @return array{
     *     return_document: ?EpcisDocument,
     *     return_completed: bool,
     *     return_created: bool,
     *     return_deferred: bool,
     *     return_deferred_reason: ?string
     * }
     */
    private function ensureDemoReturn(
        Site $site,
        bool $unpacked,
        bool $packed,
        ?SsccLabelBatch $packBatch,
    ): array {
        $existing = EpcisDocument::query()
            ->where('authored_kind', EpcisAuthoredKind::Returning)
            ->where('notes', self::DEMO_RETURN_DOCUMENT_NOTES)
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return [
                'return_document' => $existing,
                'return_completed' => true,
                'return_created' => false,
                'return_deferred' => false,
                'return_deferred_reason' => null,
            ];
        }

        $returnEpcId = $this->resolveDemoReturnEpcId($site, $unpacked, $packed, $packBatch);

        if ($returnEpcId === null) {
            return [
                'return_document' => null,
                'return_completed' => false,
                'return_created' => false,
                'return_deferred' => true,
                'return_deferred_reason' => 'Demo return EPC could not be resolved on hand at the organization site.',
            ];
        }

        try {
            $result = app(EmitReturningEpcis::class)->handle(
                [$returnEpcId],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => true],
            );
        } catch (\InvalidArgumentException $exception) {
            return [
                'return_document' => null,
                'return_completed' => false,
                'return_created' => false,
                'return_deferred' => true,
                'return_deferred_reason' => 'Demo return failed: '.$exception->getMessage(),
            ];
        }

        $document = $result['document'];
        if ($document !== null) {
            $document->forceFill(['notes' => self::DEMO_RETURN_DOCUMENT_NOTES])->save();
        }

        if ($result['returned_count'] < 1 || $document === null) {
            return [
                'return_document' => null,
                'return_completed' => false,
                'return_created' => false,
                'return_deferred' => true,
                'return_deferred_reason' => 'Demo return did not author returning EPCIS.',
            ];
        }

        return [
            'return_document' => $document->fresh(),
            'return_completed' => true,
            'return_created' => true,
            'return_deferred' => false,
            'return_deferred_reason' => null,
        ];
    }

    private function resolveDemoReturnEpcId(
        Site $site,
        bool $unpacked,
        bool $packed,
        ?SsccLabelBatch $packBatch,
    ): ?int {
        $siteId = (int) $site->getKey();
        $shippable = app(ShippableEpcsAtSite::class);

        if ($packed && $packBatch !== null) {
            $label = $packBatch->fresh(['labels'])->labels->sortBy('id')->first();
            $parentUri = $label?->sscc_urn;

            if (filled($parentUri)) {
                $packedParentId = (int) (Epc::query()->where('epc_uri', $parentUri)->value('id') ?? 0);

                if ($packedParentId > 0) {
                    return $packedParentId;
                }
            }
        }

        $targetUri = $unpacked
            ? self::DEMO_HIERARCHY_CHILD_SGTIN_URI
            : self::DEMO_HIERARCHY_SSCC_URI;

        $epcId = (int) (Epc::query()->where('epc_uri', $targetUri)->value('id') ?? 0);

        if ($epcId <= 0 || ! $shippable->contains($siteId, $epcId)) {
            return null;
        }

        return $epcId;
    }

    private function resolveOrganizationSite(): Site
    {
        $settings = TenantSettings::forTenant(tenant());
        $siteId = $settings->defaultReceiveSiteId();

        if ($siteId !== null) {
            $site = Site::query()->find($siteId);

            if ($site !== null && filled($site->gln)) {
                return $site;
            }
        }

        $site = Site::query()->where('code', 'ORG-HQ')->first();

        if ($site === null || blank($site->gln)) {
            throw new DomainException(
                'Demo organization HQ site not found. Run tracepharma:setup-demo or SeedMasterData first.',
            );
        }

        return $site;
    }

    /**
     * @return array{0: ReceivingSession, 1: bool}
     */
    private function ensureCompletedDemoReceive(Site $site): array
    {
        return $this->ensureCompletedInboundReceive(
            $site,
            ssccUri: self::DEMO_SSCC_URI,
            receiveFilename: self::DEMO_RECEIVE_FILENAME,
            documentUuid: self::DEMO_DOCUMENT_UUID,
        );
    }

    /**
     * @return array{0: ReceivingSession, 1: bool}
     */
    private function ensureCompletedInboundReceive(
        Site $site,
        string $ssccUri,
        string $receiveFilename,
        string $documentUuid,
    ): array {
        $document = $this->ensureDemoInboundDocument($receiveFilename, $documentUuid, $ssccUri);
        $created = false;

        $session = ReceivingSession::query()
            ->where('epcis_document_id', $document->getKey())
            ->first();

        if ($session === null) {
            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, (int) $site->getKey());
            $created = true;
        } elseif ($session->site_id === null) {
            $session->forceFill(['site_id' => (int) $site->getKey()])->save();
        }

        if (
            $session->status === 'completed'
            && $session->site_id !== null
            && $session->receiving_events_generated_at !== null
        ) {
            return [$session->fresh(), $created];
        }

        $epcId = Epc::query()->where('epc_uri', $ssccUri)->value('id');

        if ($epcId !== null) {
            $line = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epcId)
                ->first();

            if ($line !== null && $line->status !== 'confirmed') {
                app(ConfirmReceivingScan::class)->handle(
                    $session->fresh(),
                    $ssccUri,
                    userId: null,
                    autoConfirmChildren: true,
                );
                $session = $session->fresh();
                $created = true;
            }
        }

        if ($session->status !== 'completed' || $session->receiving_events_generated_at === null) {
            $session = app(CompleteReceivingSession::class)->handle($session->fresh());
            $created = true;
        }

        return [$session->fresh(), $created];
    }

    private function ensureDemoInboundDocument(
        string $receiveFilename,
        string $documentUuid,
        string $ssccUri,
    ): EpcisDocument {
        $existing = EpcisDocument::query()
            ->where('original_filename', $receiveFilename)
            ->where('direction', 'inbound')
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->ingestDemoFixture($receiveFilename, $documentUuid, $ssccUri);
    }

    private function ingestDemoFixture(
        string $receiveFilename,
        string $documentUuid,
        string $ssccUri,
    ): EpcisDocument {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');

        if (! is_file($fixture)) {
            throw new DomainException('Demo EPCIS fixture missing at tests/Fixtures/epcis/minimal_object_shipping.xml');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'demo_choreo_');
        if ($tmp === false) {
            throw new DomainException('Unable to create a temp file for demo EPCIS ingest.');
        }

        $xml = file_get_contents($fixture);
        if ($xml === false) {
            @unlink($tmp);

            throw new DomainException('Unable to read demo EPCIS fixture.');
        }

        $xml = str_replace('11111111-2222-3333-4444-555555555555', $documentUuid, $xml);
        $xml = str_replace(self::DEMO_SSCC_URI, $ssccUri, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => $receiveFilename,
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array{
     *     session: ?OutboundShippingSession,
     *     created: bool,
     *     completed: bool,
     *     deferred: bool,
     *     deferred_reason: ?string
     * }
     */
    private function ensureDemoShipOrder(Site $site, bool $completeShip): array
    {
        if (! TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()) {
            return [
                'session' => null,
                'created' => false,
                'completed' => false,
                'deferred' => false,
                'deferred_reason' => null,
            ];
        }

        $existing = OutboundShippingSession::query()
            ->where('asn_number', self::DEMO_SHIP_ASN)
            ->orderByDesc('id')
            ->first();

        if (
            $existing !== null
            && $existing->status === 'completed'
            && $existing->epcis_document_id !== null
        ) {
            return [
                'session' => $existing,
                'created' => false,
                'completed' => true,
                'deferred' => false,
                'deferred_reason' => null,
            ];
        }

        if (! $completeShip) {
            return [
                'session' => $existing,
                'created' => false,
                'completed' => false,
                'deferred' => true,
                'deferred_reason' => 'Outbound ship seed skipped (--receive-only). Inventory remains on hand for a live Ship Order demo.',
            ];
        }

        $epcId = (int) (Epc::query()->where('epc_uri', self::DEMO_SSCC_URI)->value('id') ?? 0);
        $shippable = $epcId > 0
            && app(ShippableEpcsAtSite::class)->contains((int) $site->getKey(), $epcId);

        if (! $shippable) {
            return [
                'session' => $existing,
                'created' => false,
                'completed' => false,
                'deferred' => true,
                'deferred_reason' => 'Demo SSCC is not shippable at the organization site (already shipped or not in custody). Open Ship Order with other on-hand inventory.',
            ];
        }

        $partner = TradingPartner::query()->where('gln', self::DOWNSTREAM_PHARMACY_GLN)->first();

        if ($partner === null) {
            return [
                'session' => null,
                'created' => false,
                'completed' => false,
                'deferred' => true,
                'deferred_reason' => 'Demo Downstream Pharmacy partner (0614141000005) not found. Run SeedMasterData first.',
            ];
        }

        $created = false;
        $session = $existing;

        if ($session === null || ! in_array($session->status, ['open', 'in_progress'], true)) {
            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $created = true;
        }

        $confirm = app(ConfirmOutboundShippingScan::class)->handle($session, self::DEMO_SSCC_URI);

        if (! $confirm['ok']) {
            return [
                'session' => $session->fresh(),
                'created' => $created,
                'completed' => false,
                'deferred' => true,
                'deferred_reason' => 'Demo SSCC could not be confirmed on Ship Order: '.$confirm['message'],
            ];
        }

        app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
            'trading_partner_id' => (int) $partner->getKey(),
        ]);

        app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
            'asn_number' => self::DEMO_SHIP_ASN,
            'customer_po' => self::DEMO_SHIP_PO,
            'dscsa_affirm' => true,
        ]);

        try {
            $completed = app(CompleteOutboundShippingSession::class)->handle($session->fresh());
        } catch (DomainException $e) {
            return [
                'session' => $session->fresh(),
                'created' => $created,
                'completed' => false,
                'deferred' => true,
                'deferred_reason' => 'Ship Order prepared; complete Send in UI: '.$e->getMessage(),
            ];
        }

        return [
            'session' => $completed,
            'created' => $created,
            'completed' => $completed->status === 'completed' && $completed->epcis_document_id !== null,
            'deferred' => false,
            'deferred_reason' => null,
        ];
    }

    /**
     * Secondary receive→transfer path using a distinct SSCC so the primary ship demo stays intact.
     *
     * @return array{
     *     receive_session: ?ReceivingSession,
     *     receive_created: bool,
     *     session: ?TransferringSession,
     *     created: bool,
     *     completed: bool,
     *     deferred: bool,
     *     deferred_reason: ?string
     * }
     */
    private function ensureDemoTransferPath(Site $hqSite, bool $completeTransfer): array
    {
        if (! TenantFeatures::forTenant(tenant())->supportsTransferring()) {
            return [
                'receive_session' => null,
                'receive_created' => false,
                'session' => null,
                'created' => false,
                'completed' => false,
                'deferred' => true,
                'deferred_reason' => 'Transferring is not available for this tenant profile.',
            ];
        }

        $branchSite = $this->ensureDemoBranchSite($hqSite);

        [$transferReceiveSession, $transferReceiveCreated] = $this->ensureCompletedInboundReceive(
            $hqSite,
            ssccUri: self::DEMO_TRANSFER_SSCC_URI,
            receiveFilename: self::DEMO_TRANSFER_RECEIVE_FILENAME,
            documentUuid: self::DEMO_TRANSFER_DOCUMENT_UUID,
        );

        $existing = TransferringSession::query()
            ->where('notes', self::DEMO_TRANSFER_SESSION_NOTES)
            ->orderByDesc('id')
            ->first();

        if (
            $existing !== null
            && $existing->status === 'completed'
            && $existing->transfer_events_generated_at !== null
            && $existing->receive_events_generated_at !== null
        ) {
            return [
                'receive_session' => $transferReceiveSession,
                'receive_created' => $transferReceiveCreated,
                'session' => $existing,
                'created' => false,
                'completed' => true,
                'deferred' => false,
                'deferred_reason' => null,
            ];
        }

        $epcId = (int) (Epc::query()->where('epc_uri', self::DEMO_TRANSFER_SSCC_URI)->value('id') ?? 0);
        $shippable = $epcId > 0
            && app(ShippableEpcsAtSite::class)->contains((int) $hqSite->getKey(), $epcId);

        if (! $shippable && ($existing === null || $existing->status === 'open')) {
            return [
                'receive_session' => $transferReceiveSession,
                'receive_created' => $transferReceiveCreated,
                'session' => $existing,
                'created' => false,
                'completed' => false,
                'deferred' => true,
                'deferred_reason' => 'Demo transfer SSCC is not on hand at organization HQ (already transferred or not in custody).',
            ];
        }

        $created = false;
        $session = $existing;

        if ($session === null || ! in_array($session->status, ['open', 'in_progress'], true)) {
            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $hqSite->getKey(),
                toSiteId: (int) $branchSite->getKey(),
                notes: self::DEMO_TRANSFER_SESSION_NOTES,
            );
            $created = true;
        }

        if ($session->status === 'open') {
            $confirm = app(ConfirmTransferringScan::class)->handle($session, self::DEMO_TRANSFER_SSCC_URI);

            if (! $confirm['ok']) {
                return [
                    'receive_session' => $transferReceiveSession,
                    'receive_created' => $transferReceiveCreated,
                    'session' => $session->fresh(),
                    'created' => $created,
                    'completed' => false,
                    'deferred' => true,
                    'deferred_reason' => 'Demo transfer SSCC could not be confirmed: '.$confirm['message'],
                ];
            }

            $session = $session->fresh();
            $created = true;
        }

        if ($session->status === 'open') {
            $session = app(CompleteTransferringSession::class)->handle($session);
            $created = true;
        }

        if (! $completeTransfer) {
            return [
                'receive_session' => $transferReceiveSession,
                'receive_created' => $transferReceiveCreated,
                'session' => $session->fresh(),
                'created' => $created,
                'completed' => false,
                'deferred' => true,
                'deferred_reason' => 'Transfer ship leg seeded; destination receive left for a live demo click.',
            ];
        }

        if ($session->status === 'in_transit') {
            $receive = app(ConfirmTransferringReceiveScan::class)->handle(
                $session->fresh(),
                self::DEMO_TRANSFER_SSCC_URI,
                generateReceiveEvents: true,
            );

            if (! $receive['ok']) {
                return [
                    'receive_session' => $transferReceiveSession,
                    'receive_created' => $transferReceiveCreated,
                    'session' => $session->fresh(),
                    'created' => $created,
                    'completed' => false,
                    'deferred' => true,
                    'deferred_reason' => 'Transfer in transit; destination receive failed: '.$receive['message'],
                ];
            }

            $session = $session->fresh();
            $created = true;
        }

        return [
            'receive_session' => $transferReceiveSession,
            'receive_created' => $transferReceiveCreated,
            'session' => $session,
            'created' => $created,
            'completed' => $session->status === 'completed'
                && $session->transfer_events_generated_at !== null
                && $session->receive_events_generated_at !== null,
            'deferred' => false,
            'deferred_reason' => null,
        ];
    }

    private function ensureDemoBranchSite(Site $hqSite): Site
    {
        $branchGln = $this->deriveDemoBranchGln($hqSite);
        $hqSiteId = (int) $hqSite->getKey();

        $existing = Site::query()
            ->whereNull('trading_partner_id')
            ->whereKeyNot($hqSiteId)
            ->where(function ($query) use ($branchGln): void {
                $query->where('code', self::DEMO_BRANCH_SITE_CODE)
                    ->orWhere('gln', $branchGln);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [self::DEMO_BRANCH_SITE_CODE])
            ->first();

        $siteAttributes = [
            'trading_partner_id' => null,
            'name' => 'Demo Organization Branch',
            'code' => self::DEMO_BRANCH_SITE_CODE,
            'gln' => $branchGln,
            'street_address' => $hqSite->street_address,
            'street_address_2' => $hqSite->street_address_2,
            'city' => $hqSite->city,
            'state' => $hqSite->state,
            'zipcode' => $hqSite->zipcode,
            'country_code' => $hqSite->country_code ?? 'US',
            'is_headquarters' => false,
            'is_active' => true,
            'is_organization_facility' => true,
        ];

        if ($existing !== null) {
            $existing->forceFill($siteAttributes)->save();

            return $existing->fresh() ?? $existing;
        }

        return Site::query()->create($siteAttributes);
    }

    private function deriveDemoBranchGln(Site $hqSite): string
    {
        $settings = TenantSettings::forTenant(tenant());
        $prefix = preg_replace('/\D+/', '', (string) ($settings->companyPrefix() ?? '')) ?? '';
        $hqGln = preg_replace('/\D+/', '', (string) $hqSite->gln) ?? '';

        if ($prefix === '') {
            foreach ([7, 6, 8, 9, 10, 11] as $length) {
                $candidate = substr($hqGln, 0, $length);

                try {
                    TenantSettings::assertValidCompanyPrefix($candidate, $hqGln);
                    $prefix = $candidate;

                    break;
                } catch (\InvalidArgumentException) {
                    continue;
                }
            }
        }

        if ($prefix === '') {
            $prefix = '0614141';
        }

        $fill = max(1, 12 - strlen($prefix));
        $suffix = 900002;

        do {
            $body = substr($prefix.str_pad((string) $suffix, $fill, '0', STR_PAD_LEFT), 0, 12);
            $branchGln = $body.Gtin::checkDigit($body);
            $suffix++;
        } while (
            $branchGln === (string) $hqSite->gln
            || Site::query()
                ->where('gln', $branchGln)
                ->whereKeyNot((int) $hqSite->getKey())
                ->exists()
        );

        return $branchGln;
    }
}
