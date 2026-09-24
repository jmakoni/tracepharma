<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\AuthorReceiveSessionException;
use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\DeleteReceivingSession;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceiveExceptionPartnerEmailDraft;
use App\Support\Receiving\ReceiveExceptionTypes;
use App\Support\Receiving\ReceiveSessionExceptionQuery;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionTypeSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ReceiveExceptionP2Test extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $extraEpcIds = [];

    private ?bool $priorRequireTi = null;

    private ?bool $priorAllowWithoutFile = null;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function schema_or_missing_epcis_authors_late_failed_and_blocks_complete(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setAllowScanFirstCompleteWithoutFile(false);
            $tenant->save();

            $asn = $this->openAsnFromFixture();
            $schema = app(AuthorReceiveSessionException::class)->lateFailedEpcis(
                $asn,
                'schema_rejected_inbound_epcis',
            );
            $this->trackCase($schema);
            $this->assertCompleteBlocked($asn, ReceiveExceptionTypes::LATE_FAILED_EPCIS);

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $scanFirst->getKey();
            try {
                app(CompleteReceivingSession::class)->handle($scanFirst->fresh());
                $this->fail('Expected DomainException for missing inbound EPCIS.');
            } catch (DomainException $e) {
                $this->assertStringContainsString(ReceiveExceptionTypes::LATE_FAILED_EPCIS, $e->getMessage());
            }
            $missing = $this->latestSessionCase($scanFirst, ReceiveExceptionTypes::LATE_FAILED_EPCIS);
            $this->assertNotNull($missing);
            $this->trackCase($missing);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_gtin_or_lot_not_on_asn_line_authors_wrong_item(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::UnitsOnly);

            $session = $this->openAsnFromFixture();
            $epc = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $this->assertNotNull($epc->gtin14);
            $this->assertNotNull($epc->serial_number);

            $ilmd = EpcIlmd::query()->find($epc->getKey());
            $this->assertNotNull($ilmd);
            $ilmd->forceFill(['lot_number' => 'ASN-LINE-LOT'])->save();
            $epc->unsetRelation('ilmd');

            $scan = ElementString::encodeSgtin(
                (string) $epc->gtin14,
                (string) $epc->serial_number,
                '606412T',
                '290531',
            );

            $result = app(ConfirmReceivingScan::class)->handle($session, $scan, null, false);

            $this->assertFalse($result['ok']);
            $wrongItem = $this->latestSessionCase($session, ReceiveExceptionTypes::WRONG_ITEM);
            $this->assertNotNull($wrongItem);
            $this->trackCase($wrongItem);
            $this->assertTrue($wrongItem->epcs()->whereKey($epc->getKey())->exists());
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function damage_quarantines_epc_and_blocks_complete_while_open(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $policy = ReceivingPolicy::forTenant($tenant);
            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $damaged = app(AuthorReceiveSessionException::class)->damaged(
                $session->fresh(),
                [(int) $epc->getKey()],
            );
            $this->trackCase($damaged);
            $this->assertTrue(
                QuarantineHold::query()
                    ->open()
                    ->where('exception_id', $damaged->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->exists(),
            );
            $this->assertCompleteBlocked($session, ReceiveExceptionTypes::DAMAGED);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cancel_session_authors_refused_without_confirmed_receive(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $cancelled = app(CancelReceivingSession::class)->handle(
                $session,
                null,
                'Driver aborted after product presented.',
            );

            $this->assertSame('cancelled', $cancelled->status);
            $refused = $this->latestSessionCase($cancelled, ReceiveExceptionTypes::REFUSED);
            $this->assertNotNull($refused);
            $this->trackCase($refused);
            $this->assertTrue(
                $refused->activities()
                    ->where('meta->reason', 'Driver aborted after product presented.')
                    ->exists(),
            );
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $cancelled->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
            $this->assertNull($cancelled->receiving_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function delete_receive_authors_refused_before_the_row_is_removed(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $sessionId = (int) $session->getKey();

            app(DeleteReceivingSession::class)->handle($session, null, 'Floor cancel deleted the session.');

            $this->assertNull(ReceivingSession::query()->find($sessionId));
            $refused = ExceptionCase::query()
                ->whereHas('type', fn ($types) => $types->where('code', ReceiveExceptionTypes::REFUSED))
                ->whereHas('activities', function ($activities) use ($sessionId): void {
                    $activities->where('meta->receiving_session_id', $sessionId)
                        ->orWhere('meta->receiving_session_id', (string) $sessionId);
                })
                ->latest('id')
                ->first();
            $this->assertNotNull($refused);
            $this->trackCase($refused);
            $this->assertTrue(
                $refused->activities()
                    ->where('meta->reason', 'Floor cancel deleted the session.')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function p0_p1_regression_overage_unknown_and_short_close(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $this->assertNotNull($session->inbound_shipment_id);

            $unknown = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                'NOT-A-REAL-BARCODE-99999',
                null,
                false,
            );
            $this->assertFalse($unknown['ok']);
            $pnd = $this->latestSessionCase($session, ReceiveExceptionTypes::PRODUCT_NO_DATA);
            $this->assertNotNull($pnd);
            $this->trackCase($pnd);

            $extra = $this->createSsccEpc();
            $extraResult = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                $extra->epc_uri,
                null,
                false,
            );
            $this->assertFalse($extraResult['ok']);
            $this->assertNull($this->latestSessionCase($session, ReceiveExceptionTypes::PRODUCT_NO_DATA)
                ?->epcs()->whereKey($extra->getKey())->first());
            $overage = $this->latestSessionCase($session, ReceiveExceptionTypes::OVERAGE);
            $this->assertNotNull($overage);
            $this->trackCase($overage);
            $this->assertTrue($overage->epcs()->whereKey($extra->getKey())->exists());

            $other = ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $this->createSsccEpc()->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'expected',
                'scan_raw' => null,
            ]);
            $session->increment('expected_parent_count');
            $policy = ReceivingPolicy::forTenant($tenant);
            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $overage->forceFill(['status' => ExceptionStatus::Closed])->save();
            $pnd->forceFill(['status' => ExceptionStatus::Closed])->save();

            $completed = app(CompleteReceivingSession::class)->handle($session->fresh(), shortClose: true);
            $this->assertSame('completed', $completed->fresh()->status);
            $this->assertSame('expected', $other->fresh()->status);
            $shortage = ReceiveSessionExceptionQuery::openCases(
                $completed,
                ReceiveExceptionTypes::SHORT_CLOSE_REQUIRED,
            )->first();
            $this->assertNotNull($shortage);
            $this->trackCase($shortage);
            $this->assertContains(
                $shortage->type?->code,
                [ReceiveExceptionTypes::DATA_NO_PRODUCT, ReceiveExceptionTypes::SHORTAGE],
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function floor_badges_include_document_hold_wrong_item_and_damaged(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $author = app(AuthorReceiveSessionException::class);
            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();

            $hold = $author->lateFailedEpcis($session, 'schema_rejected_inbound_epcis');
            $wrong = $author->wrongItem($session, $epc, ['gtin' => ['scan' => '1', 'line' => '2']]);
            $damaged = $author->damaged($session, [(int) $epc->getKey()]);
            $this->trackCase($hold);
            $this->trackCase($wrong);
            $this->trackCase($damaged);

            $counts = ReceiveSessionExceptionQuery::floorBadgeCounts($session->fresh());
            $this->assertSame(1, $counts['document_hold']);
            $this->assertSame(1, $counts['wrong_item']);
            $this->assertSame(1, $counts['damaged']);

            $blade = File::get(resource_path(
                'views/filament/app/partials/receive-exception-badges.blade.php',
            ));
            $this->assertStringContainsString('Document hold {{ $exceptionBadges[\'document_hold\'] }}', $blade);
            $this->assertStringContainsString('Wrong item {{ $exceptionBadges[\'wrong_item\'] }}', $blade);
            $this->assertStringContainsString('Damaged {{ $exceptionBadges[\'damaged\'] }}', $blade);
            $this->assertStringNotContainsString('child-epc', $blade);

            $draft = app(ReceiveExceptionPartnerEmailDraft::class)->fromSession($session->fresh());
            $this->assertStringContainsString('Receiving exception draft (not sent)', $draft);
            $this->assertStringContainsString('LATE_FAILED_EPCIS', $draft);
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

    private function latestSessionCase(ReceivingSession $session, string $type): ?ExceptionCase
    {
        return ReceiveSessionExceptionQuery::openCases($session, [$type])->first();
    }

    private function trackCase(ExceptionCase $case): void
    {
        $this->caseIds[] = (int) $case->getKey();
    }

    private function openAsnFromFixture(): ReceivingSession
    {
        $document = $this->ingestMinimalFixture();
        $this->documentId = (int) $document->getKey();
        $session = app(OpenReceivingSessionFromDocument::class)->handle(
            $document,
            $this->resolveEligibleReceiveSiteId(),
        );
        $this->sessionIds[] = (int) $session->getKey();

        return $session;
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
        $this->priorAllowWithoutFile = $settings->allowScanFirstCompleteWithoutFile();
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

        if ($this->sessionIds !== []) {
            $sessions = ReceivingSession::query()->whereIn('id', $this->sessionIds)->get();
            foreach ($sessions as $session) {
                if ($session->receiving_epcis_document_id !== null) {
                    EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
                }
                ReceivingScanLine::query()->where('receiving_session_id', $session->getKey())->delete();
            }
            ReceivingSession::query()->whereIn('id', $this->sessionIds)->delete();
            $this->sessionIds = [];
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
        if ($this->priorAllowWithoutFile !== null) {
            $settings->setAllowScanFirstCompleteWithoutFile($this->priorAllowWithoutFile);
        }
        $settings->setReceivingEdgeMode($this->priorEdgeMode);
        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile]);
        }
        $tenant->save();
        $this->priorRequireTi = null;
        $this->priorAllowWithoutFile = null;
        $this->priorEdgeMode = null;
        $this->priorProfile = null;

        tenancy()->end();
    }
}
