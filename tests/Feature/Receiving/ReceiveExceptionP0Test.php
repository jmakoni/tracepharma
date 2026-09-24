<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\AuthorReceiveSessionException;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\Exceptions\Pages\ListExceptions;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileViewReceivingSession;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceiveExceptionTypes;
use App\Support\Receiving\ReceiveSessionExceptionQuery;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPackShape;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionTypeSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ReceiveExceptionP0Test extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $extraEpcIds = [];

    private ?bool $priorRequireTi = null;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function short_close_authors_data_no_product_with_undeclared_partial_reason_and_does_not_uri_confirm(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $otherParent = $this->createSsccParentLine($session, 'expected');
            $session->increment('expected_parent_count');

            $policy = ReceivingPolicy::forTenant($tenant);
            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $completed = app(CompleteReceivingSession::class)->handle($session->fresh(), shortClose: true);
            $this->assertSame('completed', $completed->fresh()->status);
            $this->assertSame('expected', $otherParent->fresh()->status);
            $this->assertFalse(
                $this->epcAppearsOnSessionReceivingEvent($completed->fresh(), (int) $otherParent->epc_id),
                'Unscanned expected parent must not be URI-confirmed.',
            );

            $case = $this->latestSessionCase($completed, ReceiveExceptionTypes::DATA_NO_PRODUCT);
            $this->assertNotNull($case);
            $this->trackCase($case);
            $this->assertTrue(
                $case->activities()
                    ->where('meta->reason', ReceiveExceptionTypes::REASON_UNDECLARED_PARTIAL)
                    ->exists(),
            );
            $this->assertTrue($case->epcs()->whereKey((int) $otherParent->epc_id)->exists());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function unknown_scan_authors_product_no_data_without_overage(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $result = app(ConfirmReceivingScan::class)->handle(
                $session,
                'NOT-A-REAL-BARCODE-99999',
                null,
                false,
            );

            $this->assertFalse($result['ok']);
            $case = $this->latestSessionCase($session, ReceiveExceptionTypes::PRODUCT_NO_DATA);
            $this->assertNotNull($case);
            $this->trackCase($case);
            $this->assertSame(
                0,
                ReceiveSessionExceptionQuery::openCases($session, [ReceiveExceptionTypes::OVERAGE])->count(),
                'Unknown scan is PRODUCT_NO_DATA only — OVERAGE requires a bound extra serial.',
            );
            $this->assertNull(
                app(ReceivingGate::class)->documentBlockedByOpenException($session->document),
                'Session-authored PRODUCT_NO_DATA must not document-block the inbound file.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function extra_serial_on_bound_shipment_authors_overage_not_product_no_data(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $this->assertNotNull($session->inbound_shipment_id, 'ASN session must bind a shipment for OVERAGE.');

            $extra = $this->createSsccEpc();
            $result = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                $extra->epc_uri,
                null,
                false,
            );

            $this->assertFalse($result['ok']);
            $this->assertSame('unexpected', $result['effect']);

            $this->assertNull($this->latestSessionCase($session, ReceiveExceptionTypes::PRODUCT_NO_DATA));
            $overage = $this->latestSessionCase($session, ReceiveExceptionTypes::OVERAGE);
            $this->assertNotNull($overage);
            $this->trackCase($overage);
            $this->assertTrue($overage->epcs()->whereKey($extra->getKey())->exists());
            $this->assertTrue(
                QuarantineHold::query()
                    ->open()
                    ->where('exception_id', $overage->getKey())
                    ->where('epc_id', $extra->getKey())
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function sealed_empty_aggregation_authors_aggregation_break_and_rejects_confirm(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertTrue($policy->requiresAggregationChildrenOnConfirm());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $orphan = $this->createSsccEpc();
            $this->assertSame(0, ReceivingPackShape::openChildCount($orphan));

            $result = app(ConfirmReceivingScan::class)->handle(
                $session,
                $orphan->epc_uri,
                null,
                true,
            );

            $this->assertFalse($result['ok']);
            $this->assertSame('missing_aggregation', $result['effect']);
            $case = $this->latestSessionCase($session, ReceiveExceptionTypes::AGGREGATION_BREAK);
            $this->assertNotNull($case);
            $this->trackCase($case);
            $this->assertTrue($case->epcs()->whereKey($orphan->getKey())->exists());
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $orphan->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function complete_hard_blocks_product_no_data_aggregation_break_and_damaged(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $author = app(AuthorReceiveSessionException::class);

            $session = $this->openAsnFromFixture();
            $policy = ReceivingPolicy::forTenant($tenant);
            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $productNoData = $author->productNoData($session->fresh(), [], null, 'P0 product no data');
            $this->trackCase($productNoData);
            $this->assertCompleteBlocked($session, ReceiveExceptionTypes::PRODUCT_NO_DATA);

            $productNoData->forceFill(['status' => ExceptionStatus::Closed])->save();

            $orphan = $this->createSsccEpc();
            $agg = $author->aggregationBreak($session->fresh(), $orphan);
            $this->trackCase($agg);
            $this->assertCompleteBlocked($session, ReceiveExceptionTypes::AGGREGATION_BREAK);

            $agg->forceFill(['status' => ExceptionStatus::Closed])->save();

            $damagedEpc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $damaged = $author->handle(
                $session->fresh(),
                ReceiveExceptionTypes::DAMAGED,
                'Damaged · receiving #'.$session->getKey(),
                'P0 damaged in session',
                [(int) $damagedEpc->getKey()],
                ['source' => 'receive_scan'],
            );
            $this->trackCase($damaged);
            $this->assertCompleteBlocked($session, ReceiveExceptionTypes::DAMAGED);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function short_close_complete_is_allowed_when_data_no_product_or_shortage_exists(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $otherParent = $this->createSsccParentLine($session, 'expected');
            $session->increment('expected_parent_count');

            $policy = ReceivingPolicy::forTenant($tenant);
            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $completed = app(CompleteReceivingSession::class)->handle($session->fresh(), shortClose: true);
            $this->assertSame('completed', $completed->fresh()->status);
            $this->assertNotEmpty(
                ReceiveSessionExceptionQuery::openCases(
                    $completed,
                    ReceiveExceptionTypes::SHORT_CLOSE_REQUIRED,
                ),
            );
            $this->trackOpenSessionCases($completed);
            $this->assertSame('expected', $otherParent->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function floor_shows_shortage_no_data_and_quarantine_badges_without_child_epc_list(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $session = $this->openAsnFromFixture();
            $author = app(AuthorReceiveSessionException::class);
            $unscanned = $this->createSsccParentLine($session, 'expected');
            $extra = $this->createSsccEpc();

            $shortage = $author->dataNoProduct($session, [(int) $unscanned->epc_id], $user);
            $noData = $author->productNoData($session, [(int) $extra->getKey()], $user, alsoOverage: true);
            $this->trackCase($shortage);
            $this->trackCase($noData);
            foreach (ReceiveSessionExceptionQuery::openCases($session, [ReceiveExceptionTypes::OVERAGE]) as $overage) {
                $this->trackCase($overage);
            }

            $counts = ReceiveSessionExceptionQuery::floorBadgeCounts($session->fresh());
            $this->assertSame(1, $counts['shortage']);
            $this->assertSame(1, $counts['no_data']);
            $this->assertGreaterThanOrEqual(1, $counts['quarantine']);

            $blade = File::get(resource_path(
                'views/filament/app/partials/receive-exception-badges.blade.php',
            ));
            $this->assertStringContainsString('Shortage {{ $exceptionBadges[\'shortage\'] }}', $blade);
            $this->assertStringContainsString('No data {{ $exceptionBadges[\'no_data\'] }}', $blade);
            $this->assertStringContainsString('Quarantine {{ $exceptionBadges[\'quarantine\'] }}', $blade);
            $this->assertStringNotContainsString('child-epc', $blade);
            $this->assertStringNotContainsString('aggregation children', strtolower($blade));

            $this->assertTrue(ExceptionResource::canAccess());
            $component = Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSuccessful()
                ->assertSee('Shortage 1')
                ->assertSee('No data 1')
                ->assertSee('Quarantine '.$counts['quarantine'])
                ->assertDontSee(self::SGTIN_URI);

            $noDataUrl = $component->instance()->receiveExceptionInboxUrl('no_data');
            $this->assertNotNull($noDataUrl);
            $this->assertStringContainsString('/exceptions', parse_url($noDataUrl, PHP_URL_PATH) ?? $noDataUrl);
            $this->assertStringContainsString('receiving_session_id', $noDataUrl);
            $this->assertStringContainsString((string) $session->getKey(), $noDataUrl);
            $this->assertStringContainsString('PRODUCT_NO_DATA', $noDataUrl);
            $html = $component->html();
            $this->assertStringContainsString('/exceptions', $html);
            $this->assertStringContainsString('PRODUCT_NO_DATA', $html);
            $this->assertStringContainsString((string) $session->getKey(), $html);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function exceptions_filter_returns_session_product_no_data_case(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->actingAs($this->createOwnerUser());

            $session = $this->openAsnFromFixture();
            $extra = $this->createSsccEpc();
            $noData = app(AuthorReceiveSessionException::class)->productNoData(
                $session,
                [(int) $extra->getKey()],
                auth()->user(),
            );
            $this->trackCase($noData);

            $noise = ExceptionCase::query()->create([
                'exception_type_id' => $noData->exception_type_id,
                'document_id' => $session->epcis_document_id,
                'trading_partner_id' => $session->trading_partner_id,
                'site_id' => $session->site_id,
                'title' => 'Unrelated product no data',
                'description' => 'No receiving_session_id activity',
                'severity' => $noData->severity?->value ?? 'high',
                'status' => ExceptionStatus::New->value,
            ]);
            $this->trackCase($noise);

            Livewire::test(ListExceptions::class)
                ->set('activeTab', 'all_open')
                ->set('tableFilters.receiving_session_id.value', (string) $session->getKey())
                ->set('tableFilters.type_code.value', ReceiveExceptionTypes::PRODUCT_NO_DATA)
                ->assertCanSeeTableRecords([$noData])
                ->assertCanNotSeeTableRecords([$noise]);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function assertCompleteBlocked(ReceivingSession $session, string $type): void
    {
        try {
            app(CompleteReceivingSession::class)->handle($session->fresh());
            $this->fail("Expected DomainException for open {$type}.");
        } catch (DomainException $e) {
            $this->assertStringContainsString($type, $e->getMessage());
        }
    }

    private function openAsnFromFixture(): ReceivingSession
    {
        $document = $this->ingestMinimalFixture();
        $this->documentId = (int) $document->getKey();
        $session = app(OpenReceivingSessionFromDocument::class)->handle(
            $document,
            $this->resolveEligibleReceiveSiteId(),
        );
        $this->sessionId = (int) $session->getKey();

        return $session;
    }

    private function latestSessionCase(ReceivingSession $session, string $type): ?ExceptionCase
    {
        return ReceiveSessionExceptionQuery::openCases($session, [$type])->first();
    }

    private function trackCase(ExceptionCase $case): void
    {
        $this->caseIds[] = (int) $case->getKey();
    }

    private function trackOpenSessionCases(ReceivingSession $session): void
    {
        foreach (ReceiveSessionExceptionQuery::openCases($session) as $case) {
            $this->trackCase($case);
        }
    }

    private function createSsccParentLine(ReceivingSession $session, string $status): ReceivingScanLine
    {
        $epc = $this->createSsccEpc();

        return ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $epc->getKey(),
            'parent_epc_id' => null,
            'line_role' => 'parent',
            'status' => $status,
            'scan_raw' => $epc->epc_uri,
        ]);
    }

    private function createSsccEpc(): Epc
    {
        do {
            $serial = '0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $uri = 'urn:epc:id:sscc:030116.'.$serial;
        } while (Epc::query()->where('epc_uri', $uri)->exists());

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->extraEpcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) Str::uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function epcAppearsOnSessionReceivingEvent(ReceivingSession $session, int $epcId): bool
    {
        $documentId = $session->receiving_epcis_document_id;
        if ($documentId === null) {
            return false;
        }

        $eventIds = EpcisEvent::query()
            ->where('document_id', $documentId)
            ->where(function ($query): void {
                $query->where('biz_step', 'like', '%:receiving')
                    ->orWhere('biz_step', 'like', '%:accepting');
            })
            ->pluck('id');

        if ($eventIds->isEmpty()) {
            return false;
        }

        return DB::table('event_epcs')
            ->where('epc_id', $epcId)
            ->whereIn('event_id', $eventIds)
            ->exists();
    }

    private function resolveEligibleReceiveSiteId(): ?int
    {
        $sites = app(EligibleReceiveSites::class)->options();

        return $sites === [] ? null : (int) array_key_first($sites);
    }

    private function setEdgeMode(Tenant $tenant, ReceivingEdgeMode $mode): void
    {
        TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
        TenantSettings::forTenant($tenant)->setReceivingEdgeMode($mode);
        $tenant->save();
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'p0-recv-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user;
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

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);
        if ($tenant->profile !== TenantProfile::Pharmacy) {
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
        }

        tenancy()->initialize($tenant);
        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        $settings = TenantSettings::forTenant($tenant);
        $this->priorRequireTi = $settings->requireTiForScanFirst();
        $this->priorEdgeMode = $settings->receivingEdgeMode();

        app(ExceptionTypeSeeder::class)->run();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->caseIds !== []) {
            QuarantineHold::query()->whereIn('exception_id', $this->caseIds)->delete();
            ExceptionActivity::query()->whereIn('exception_id', $this->caseIds)->delete();
            foreach ($this->caseIds as $caseId) {
                ExceptionCase::query()->find($caseId)?->epcs()->detach();
            }
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        if ($this->sessionId !== null) {
            $session = ReceivingSession::query()->find($this->sessionId);
            if ($session?->receiving_epcis_document_id !== null) {
                EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
            }
            ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->documentId !== null) {
            ReceivingScanLine::query()
                ->whereIn(
                    'receiving_session_id',
                    ReceivingSession::query()->where('epcis_document_id', $this->documentId)->select('id'),
                )
                ->delete();
            ReceivingSession::query()->where('epcis_document_id', $this->documentId)->delete();
            DB::table('event_epcs')->whereIn(
                'event_id',
                DB::table('epcis_events')->where('document_id', $this->documentId)->select('id'),
            )->delete();
            DB::table('epcis_events')->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        if ($this->extraEpcIds !== []) {
            QuarantineHold::query()->whereIn('epc_id', $this->extraEpcIds)->delete();
            ReceivingScanLine::query()->whereIn('epc_id', $this->extraEpcIds)->delete();
            Epc::query()->whereIn('id', $this->extraEpcIds)->delete();
            $this->extraEpcIds = [];
        }

        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorRequireTi !== null) {
            $settings->setRequireTiForScanFirst($this->priorRequireTi);
        }
        $settings->setReceivingEdgeMode($this->priorEdgeMode);
        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile]);
        }
        $tenant->save();
        $this->priorRequireTi = null;
        $this->priorEdgeMode = null;
        $this->priorProfile = null;

        tenancy()->end();
    }
}
