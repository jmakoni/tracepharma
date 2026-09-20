<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Receiving\OutstandingReceiveTargets;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\TenantSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutstandingReceiveTargetsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $linkIds = [];

    private ?int $eventId = null;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    #[Test]
    public function sop_filters_outstanding_expected_containers(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(100000, 999999);
            $pallet = $this->epc('urn:epc:id:sscc:030116.01040'.$suffix);
            $case = $this->epc('urn:epc:id:sscc:030116.01041'.$suffix);
            $unit = $this->epc('urn:epc:id:sgtin:030116.0200116.8'.$suffix);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'creation_date' => now(),
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $event = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'AggregationEvent',
                'action' => 'ADD',
                'event_time' => now(),
                'record_time' => now(),
            ]);
            $this->eventId = (int) $event->getKey();

            $this->link($pallet, $case, $event);
            $this->link($case, $unit, $event);

            $session = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::InboundAsn,
                'epcis_document_id' => $document->getKey(),
                'status' => 'open',
                'opened_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            foreach ([$pallet, $case, $unit] as $epc) {
                ReceivingScanLine::query()->create([
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $epc->getKey(),
                    'line_role' => $epc->epc_type === 'sscc' && (int) $epc->getKey() === (int) $pallet->getKey() ? 'parent' : 'child',
                    'status' => 'expected',
                ]);
            }

            $settings = TenantSettings::forTenant($tenant);
            $this->priorEdgeMode = $settings->receivingEdgeMode();

            $settings->setReceivingEdgeMode(ReceivingEdgeMode::SealedParent);
            $tenant->save();
            $palletList = app(OutstandingReceiveTargets::class)->forSession($session->fresh());
            $this->assertSame('SSCC still to scan', $palletList['heading']);
            $this->assertSame(['SSCC', 'SSCC'], array_column($palletList['rows'], 'type'));
            $this->assertStringContainsString((string) $pallet->sscc18, json_encode($palletList['rows']));
            $this->assertStringContainsString((string) $case->sscc18, json_encode($palletList['rows']));
            $this->assertStringNotContainsString((string) $unit->serial_number, json_encode($palletList['rows']));

            $settings->setReceivingEdgeMode(ReceivingEdgeMode::CaseOnly);
            $tenant->save();
            $caseList = app(OutstandingReceiveTargets::class)->forSession($session->fresh());
            $this->assertSame('Cases still to scan', $caseList['heading']);
            $this->assertSame(['SSCC'], array_column($caseList['rows'], 'type'));
            $this->assertStringContainsString((string) $case->sscc18, $caseList['rows'][0]['label']);
            $this->assertStringNotContainsString((string) $pallet->sscc18, json_encode($caseList['rows']));

            $settings->setReceivingEdgeMode(ReceivingEdgeMode::UnitsOnly);
            $tenant->save();
            $unitList = app(OutstandingReceiveTargets::class)->forSession($session->fresh());
            $this->assertSame('Units still to scan', $unitList['heading']);
            $this->assertSame(['Unit'], array_column($unitList['rows'], 'type'));
            $this->assertStringContainsString((string) $unit->serial_number, $unitList['rows'][0]['label']);
        } finally {
            $this->cleanup($tenant ?? null);
        }
    }

    private function epc(string $uri): Epc
    {
        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function link(Epc $parent, Epc $child, EpcisEvent $event): void
    {
        $link = AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $child->getKey(),
            'link_type' => 'contains',
            'established_by_event_id' => $event->getKey(),
            'valid_from' => now(),
            'valid_to' => null,
        ]);
        $this->linkIds[] = (int) $link->getKey();
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(?Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($tenant instanceof Tenant) {
            TenantSettings::forTenant($tenant)->setReceivingEdgeMode($this->priorEdgeMode);
            $tenant->save();
        }

        if ($this->sessionId !== null) {
            ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
        }

        if ($this->linkIds !== []) {
            AggregationLink::query()->whereIn('id', $this->linkIds)->delete();
        }

        if ($this->eventId !== null) {
            EpcisEvent::query()->whereKey($this->eventId)->delete();
        }

        if ($this->documentId !== null) {
            EpcisDocument::query()->whereKey($this->documentId)->delete();
        }

        if ($this->epcIds !== []) {
            Epc::query()->whereIn('id', $this->epcIds)->delete();
        }

        tenancy()->end();
    }
}
