<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Receiving;

use App\Actions\Receiving\SeedReceivingAsnParentChildren;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeedReceivingAsnParentChildrenTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function seeds_child_scan_lines_from_open_aggregation_links(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(100000, 999999);
            $parentUri = 'urn:epc:id:sscc:030116.01040'.$suffix;
            $childUri = 'urn:epc:id:sgtin:030116.0200116.7'.$suffix;

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'creation_date' => now()->subHour(),
                'received_at' => now()->subHour(),
            ]);
            $this->documentId = (int) $document->getKey();

            $event = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'AggregationEvent',
                'action' => 'ADD',
                'event_time' => now()->subHour(),
                'record_time' => now()->subHour(),
            ]);

            $parent = Epc::query()->create(Epc::materializeAttributesFromUri($parentUri));
            $child = Epc::query()->create(Epc::materializeAttributesFromUri($childUri));
            $this->epcIds = [(int) $parent->getKey(), (int) $child->getKey()];
            $this->assertSame('sscc', $parent->epc_type);
            $this->assertSame('sgtin', $child->epc_type);

            AggregationLink::query()->create([
                'parent_epc_id' => $parent->getKey(),
                'child_epc_id' => $child->getKey(),
                'link_type' => 'contains',
                'established_by_event_id' => $event->getKey(),
                'valid_from' => now()->subHour(),
                'valid_to' => null,
            ]);

            $session = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::InboundAsn,
                'epcis_document_id' => $document->getKey(),
                'status' => 'open',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $parent->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            $result = app(SeedReceivingAsnParentChildren::class)->handle(
                $session->fresh(),
                $parent->fresh(),
            );

            $this->assertSame([(int) $child->getKey()], $result['child_epc_ids']);
            $this->assertSame(1, $result['expected_child_count']);
            $this->assertSame(1, $result['parent_expected_children']);
            $this->assertSame(0, $result['parent_confirmed_children']);
            $this->assertTrue(
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('epc_id', $child->getKey())
                    ->where('line_role', 'child')
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('status', 'expected')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
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

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionId !== null) {
            ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->epcIds !== []) {
            DB::table('aggregation_links')
                ->whereIn('parent_epc_id', $this->epcIds)
                ->orWhereIn('child_epc_id', $this->epcIds)
                ->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->documentId !== null) {
            EpcisEvent::query()->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
