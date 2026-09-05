<?php

namespace Tests\Feature\Shipping;

use App\Actions\Shipping\ValidateOutboundShippingSend;
use App\Enums\EpcisAuthoredKind;
use App\Enums\ExceptionStatus;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Labeling\SsccBuilder;
use App\Support\Shipping\AssertOutermostSsccHasChildren;
use App\Support\Shipping\SsccShipCompletenessException;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssertOutermostSsccHasChildrenTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $batchIds = [];

    /** @var list<int> */
    private array $labelIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $linkIds = [];

    /** @var list<int> */
    private array $shippingSessionIds = [];

    /** @var list<int> */
    private array $exceptionCaseIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    #[Test]
    public function full_tree_open_links_match_cumulative_add_passes(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            [$parent, $childA, $childB] = $this->seedTenantSsccWithChildren($site, 2);

            app(AssertOutermostSsccHasChildren::class)->handle($parent);

            $this->assertTrue(true);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function empty_tenant_sscc_blocks_with_packed_children_message(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            [$parent] = $this->seedTenantSsccWithChildren($site, 0);

            try {
                app(AssertOutermostSsccHasChildren::class)->handle($parent);
                $this->fail('Expected empty plate to throw.');
            } catch (SsccShipCompletenessException $exception) {
                $this->assertTrue($exception->isEmptyPlate());
                $this->assertSame('MISSING_CHILDREN', $exception->exceptionTypeCode);
                $this->assertStringContainsString('no packed children', strtolower($exception->getMessage()));
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function missing_open_child_vs_add_set_blocks_as_broken_aggregation(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            [$parent, $childA, $childB] = $this->seedTenantSsccWithChildren($site, 2);

            AggregationLink::query()
                ->open()
                ->where('parent_epc_id', $parent->getKey())
                ->where('child_epc_id', $childB->getKey())
                ->update(['valid_to' => now()]);

            try {
                app(AssertOutermostSsccHasChildren::class)->handle($parent);
                $this->fail('Expected hierarchy mismatch to throw.');
            } catch (SsccShipCompletenessException $exception) {
                $this->assertSame('BROKEN_AGGREGATION', $exception->exceptionTypeCode);
                $this->assertFalse($exception->isEmptyPlate());
                $this->assertStringContainsString('incomplete', strtolower($exception->getMessage()));
                $this->assertContains((int) $childB->getKey(), $exception->affectedChildEpcIds);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function decommissioned_open_child_does_not_count_as_present(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            [$parent, $child] = $this->seedTenantSsccWithChildren($site, 1);

            $this->authorTerminalEvent($site, $child, 'urn:epcglobal:cbv:disp:decommissioned');

            try {
                app(AssertOutermostSsccHasChildren::class)->handle($parent);
                $this->fail('Expected terminal child to fail completeness.');
            } catch (SsccShipCompletenessException $exception) {
                $this->assertSame('BROKEN_AGGREGATION', $exception->exceptionTypeCode);
                $this->assertContains((int) $child->getKey(), $exception->affectedChildEpcIds);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cumulative_add_across_incremental_events_passes_when_all_links_open(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            [$parent, $first] = $this->seedTenantSsccWithChildren($site, 1);
            $second = $this->createChildEpc();
            $this->authorAggregationAdd($parent, [$second], now()->addMinute());
            $this->openLink($parent, $second);

            app(AssertOutermostSsccHasChildren::class)->handle($parent);

            $this->assertTrue(true);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_validation_opens_broken_aggregation_case_and_blocks(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);
            $this->ensureExceptionTypes();

            $site = $this->createCommissionSite($tenant);
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $site->id);
            $this->actingAsWithSiteAccess($site);

            [$parent, $childA, $childB] = $this->seedTenantSsccWithChildren($site, 2);
            AggregationLink::query()
                ->open()
                ->where('parent_epc_id', $parent->getKey())
                ->where('child_epc_id', $childB->getKey())
                ->update(['valid_to' => now()]);

            $session = OutboundShippingSession::query()->create([
                'site_id' => $site->getKey(),
                'status' => 'open',
                'opened_by' => auth()->id(),
                'opened_at' => now(),
                'confirmed_count' => 1,
            ]);
            $this->shippingSessionIds[] = (int) $session->getKey();

            OutboundShippingScanLine::query()->create([
                'outbound_shipping_session_id' => $session->getKey(),
                'epc_id' => $parent->getKey(),
                'scanned_value' => (string) $parent->epc_uri,
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            $before = ExceptionCase::query()->count();
            $blockers = app(ValidateOutboundShippingSend::class)->handle($session->fresh() ?? $session);

            $this->assertTrue(
                collect($blockers)->contains(
                    fn (string $blocker): bool => str_contains(strtolower($blocker), 'incomplete'),
                ),
                'Blockers: '.implode(' | ', $blockers),
            );

            $type = ExceptionType::query()->where('code', 'BROKEN_AGGREGATION')->firstOrFail();
            $case = ExceptionCase::query()
                ->where('exception_type_id', $type->getKey())
                ->where('status', ExceptionStatus::New->value)
                ->whereHas('epcs', fn ($q) => $q->where('epcs.id', $parent->getKey()))
                ->latest('id')
                ->first();

            $this->assertNotNull($case);
            $this->assertGreaterThan($before, ExceptionCase::query()->count());
            $this->exceptionCaseIds[] = (int) $case->getKey();

            app(ValidateOutboundShippingSend::class)->handle($session->fresh() ?? $session);
            $this->assertSame(
                1,
                ExceptionCase::query()
                    ->where('exception_type_id', $type->getKey())
                    ->whereNotIn('status', [
                        ExceptionStatus::Resolved->value,
                        ExceptionStatus::Closed->value,
                        ExceptionStatus::Cancelled->value,
                    ])
                    ->whereHas('epcs', fn ($q) => $q->where('epcs.id', $parent->getKey()))
                    ->count(),
                'Send validation must de-dupe open hierarchy cases for the same parent.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: Epc, ...list<Epc>}
     */
    private function seedTenantSsccWithChildren(Site $site, int $childCount): array
    {
        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '0399991',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 1,
            'status' => SsccLabelBatchStatus::Completed,
            'commission_site_id' => $site->getKey(),
            'commissioned_at' => now(),
        ]);
        $this->batchIds[] = (int) $batch->getKey();

        $built = app(SsccBuilder::class)->build('0399991', random_int(100000, 999999), 0);
        $label = SsccLabel::query()->create([
            'batch_id' => $batch->getKey(),
            'sscc_18' => $built['sscc_18'],
            'sscc_urn' => $built['sscc_urn'],
            'extension_digit' => $built['extension_digit'],
            'company_prefix' => $built['company_prefix'],
            'serial_reference' => $built['serial_reference'],
            'serial_reference_int' => $built['serial_reference_int'],
            'element_string' => $built['element_string'],
            'hrt' => $built['hrt'],
            'label_disk' => 'local',
            'label_path' => 'labels/sscc/tp403-'.$built['sscc_18'].'.pdf',
            'commissioned_at' => now(),
        ]);
        $this->labelIds[] = (int) $label->getKey();

        $parent = Epc::query()->firstOrCreate(
            ['epc_uri' => $built['sscc_urn']],
            Epc::materializeAttributesFromUri($built['sscc_urn']),
        );
        $this->epcIds[] = (int) $parent->getKey();

        $children = [];
        for ($i = 0; $i < $childCount; $i++) {
            $children[] = $this->createChildEpc();
        }

        if ($children !== []) {
            $this->authorAggregationAdd($parent, $children, now());
            foreach ($children as $child) {
                $this->openLink($parent, $child);
            }
        }

        return [$parent, ...$children];
    }

    private function createChildEpc(): Epc
    {
        $uri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
        $child = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $child->getKey();

        return $child;
    }

    /**
     * @param  list<Epc>  $children
     */
    private function authorAggregationAdd(Epc $parent, array $children, $eventTime): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::SsccAggregation,
            'status' => 'parsed',
            'original_filename' => 'tp403-agg-'.Str::random(6).'.xml',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'AggregationEvent',
            'event_time' => $eventTime,
            'record_time' => $eventTime,
            'event_timezone_offset' => '+00:00',
            'action' => 'ADD',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
        ]);
        $this->eventIds[] = (int) $event->getKey();

        $rows = [[
            'event_id' => $event->getKey(),
            'epc_id' => $parent->getKey(),
            'role' => 'parentID',
        ]];
        foreach ($children as $child) {
            $rows[] = [
                'event_id' => $event->getKey(),
                'epc_id' => $child->getKey(),
                'role' => 'childEPC',
            ];
        }
        DB::table('event_epcs')->insert($rows);
    }

    private function openLink(Epc $parent, Epc $child): void
    {
        $link = AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $child->getKey(),
            'established_by_event_id' => $this->eventIds[array_key_last($this->eventIds)] ?? null,
            'link_type' => 'aggregation',
            'valid_from' => now()->subMinute(),
            'valid_to' => null,
        ]);
        $this->linkIds[] = (int) $link->getKey();
    }

    private function authorTerminalEvent(Site $site, Epc $epc, string $disposition): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'tp403-terminal-'.Str::random(6).'.xml',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->addMinutes(5),
            'record_time' => now()->addMinutes(5),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:decommissioning',
            'disposition' => $disposition,
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insert([
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]);
    }

    private function ensureExceptionTypes(): void
    {
        if (ExceptionType::query()->where('code', 'BROKEN_AGGREGATION')->exists()) {
            return;
        }

        (new ExceptionTypeSeeder)->run();
    }

    private function actingAsWithSiteAccess(Site $site): User
    {
        $user = User::factory()->create();
        $user->syncSites([(int) $site->id], (int) $site->id);
        $this->actingAs($user);

        return $user;
    }

    private function createCommissionSite(Tenant $tenant, ?string $gln = null): Site
    {
        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }

        $sitePrefix = $settings->companyPrefix() ?? '0399991';

        $site = Site::query()->create([
            'name' => 'TP403 Site '.Str::random(6),
            'gln' => $gln ?? $this->uniqueSiteGln($sitePrefix),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
            'country' => 'US',
            'state' => 'TX',
        ]);
        $this->siteIds[] = (int) $site->id;

        $settings->setDefaultShipFromSiteId((int) $site->id);
        $tenant->save();

        return $site;
    }

    private function uniqueSiteGln(string $companyPrefix = '0399991'): string
    {
        $locationLen = 12 - strlen($companyPrefix);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $body = $companyPrefix.str_pad((string) random_int(0, (10 ** $locationLen) - 1), $locationLen, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split(strrev($body)) as $index => $digit) {
                $sum += ((int) $digit) * ($index % 2 === 0 ? 3 : 1);
            }
            $check = (10 - ($sum % 10)) % 10;
            $gln = $body.$check;
            if (! Site::query()->where('gln', $gln)->exists()) {
                return $gln;
            }
        }

        return $companyPrefix.str_pad('1', $locationLen, '0').'0';
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

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

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

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        $tenant->forceFill(['profile' => $profile])->save();
        $tenant->refresh();
    }

    private function cleanup(Tenant $tenant): void
    {
        if ($this->exceptionCaseIds !== []) {
            DB::table('exception_epcs')->whereIn('exception_id', $this->exceptionCaseIds)->delete();
            ExceptionCase::query()->whereIn('id', $this->exceptionCaseIds)->delete();
        }

        if ($this->shippingSessionIds !== []) {
            OutboundShippingScanLine::query()
                ->whereIn('outbound_shipping_session_id', $this->shippingSessionIds)
                ->delete();
            OutboundShippingSession::query()->whereIn('id', $this->shippingSessionIds)->delete();
        }

        if ($this->linkIds !== []) {
            AggregationLink::query()->whereIn('id', $this->linkIds)->delete();
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }

        if ($this->labelIds !== []) {
            DB::table('sscc_label_children')->whereIn('sscc_label_id', $this->labelIds)->delete();
            SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
        }

        if ($this->batchIds !== []) {
            SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
        }

        if ($this->epcIds !== []) {
            Epc::query()->whereIn('id', $this->epcIds)->delete();
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }

        if ($this->priorDefaultShipFromSiteId !== null) {
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
        }

        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
        }

        tenancy()->end();
    }
}
