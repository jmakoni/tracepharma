<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\AuthorReceiveSessionException;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EventParty;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\Sgln;
use App\Support\Receiving\EligibleReceiveSites;
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

class ReceiveExceptionP1Test extends TestCase
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

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function lot_expiry_mismatch_vs_file_authors_pi_mismatch_and_does_not_confirm(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::UnitsOnly);

            $session = $this->openAsnFromFixture();
            $epc = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $this->assertNotNull($epc->gtin14);
            $this->assertNotNull($epc->serial_number);

            $scan = ElementString::encodeSgtin(
                (string) $epc->gtin14,
                (string) $epc->serial_number,
                'WRONGLOT',
                '270101',
            );

            $result = app(ConfirmReceivingScan::class)->handle($session, $scan, null, false);

            $this->assertFalse($result['ok']);
            $this->assertSame('pi_mismatch', $result['effect']);
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );

            $case = $this->latestSessionCase($session, ReceiveExceptionTypes::PI_MISMATCH);
            $this->assertNotNull($case);
            $this->trackCase($case);
            $this->assertTrue($case->epcs()->whereKey($epc->getKey())->exists());
            $this->assertTrue(
                $case->activities()->whereNotNull('meta->scan_raw')->exists()
                    || $case->activities()->whereNotNull('meta->lot')->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function same_epc_on_second_session_authors_duplicate_serial(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $first = $this->openAsnFromFixture();
            $policy = ReceivingPolicy::forTenant($tenant);
            $confirm = app(ConfirmReceivingScan::class)->handle(
                $first,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'first confirm failed');

            $second = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $second->getKey();

            $result = app(ConfirmReceivingScan::class)->handle(
                $second,
                self::SSCC_URI,
                null,
                false,
            );

            $this->assertFalse($result['ok']);
            $case = $this->latestSessionCase($second, ReceiveExceptionTypes::DUPLICATE_SERIAL);
            $this->assertNotNull($case);
            $this->trackCase($case);
            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $this->assertTrue($case->epcs()->whereKey($epc->getKey())->exists());
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $second->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_to_other_site_sgln_authors_wrong_destination_and_does_not_confirm(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $siteGln = Sgln::normalizeGln(Site::query()->whereKey($session->site_id)->value('gln'));
            $this->assertNotNull($siteGln);

            $otherGln = $siteGln === '0614141000005' ? '0301160000009' : '0614141000005';
            $eventId = EpcisEvent::query()
                ->where('document_id', $session->epcis_document_id)
                ->orderBy('id')
                ->value('id');
            $this->assertNotNull($eventId);

            EventParty::query()->create([
                'event_id' => $eventId,
                'party_role' => 'destination',
                'gln' => $otherGln,
                'gln_uri' => 'urn:epc:id:sgln:'.substr($otherGln, 0, 6).'.'.substr($otherGln, 6, 6).'.0',
                'extra_json' => ['source_dest_type' => 'location'],
            ]);
            EpcisDocument::query()->whereKey($session->epcis_document_id)->update([
                'ship_to_gln' => $otherGln,
            ]);

            $result = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                self::SSCC_URI,
                null,
                false,
            );

            $this->assertFalse($result['ok']);
            $this->assertSame('wrong_destination', $result['effect']);
            $case = $this->latestSessionCase($session, ReceiveExceptionTypes::WRONG_DESTINATION);
            $this->assertNotNull($case);
            $this->trackCase($case);
            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
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
    public function bound_asn_extra_serial_authors_overage_not_product_no_data(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $this->assertNotNull($session->inbound_shipment_id);

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
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function unresolved_overage_blocks_complete(): void
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

            $extra = $this->createSsccEpc();
            $overage = app(AuthorReceiveSessionException::class)->overage(
                $session->fresh(),
                [(int) $extra->getKey()],
            );
            $this->trackCase($overage);

            try {
                app(CompleteReceivingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException for open OVERAGE.');
            } catch (DomainException $e) {
                $this->assertStringContainsString(ReceiveExceptionTypes::OVERAGE, $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function floor_badges_include_mismatch_overage_and_wrong_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $session = $this->openAsnFromFixture();
            $author = app(AuthorReceiveSessionException::class);
            $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();

            $mismatch = $author->piMismatch($session, $epc, ['lot' => ['scan' => 'A', 'file' => 'B']]);
            $overage = $author->overage($session, [(int) $epc->getKey()]);
            $wrong = $author->wrongDestination($session, $epc, ['file' => '0614141000005', 'site' => '0301160000009']);
            $this->trackCase($mismatch);
            $this->trackCase($overage);
            $this->trackCase($wrong);

            $counts = ReceiveSessionExceptionQuery::floorBadgeCounts($session->fresh());
            $this->assertSame(1, $counts['mismatch']);
            $this->assertSame(1, $counts['overage']);
            $this->assertSame(1, $counts['wrong_site']);

            $blade = File::get(resource_path(
                'views/filament/app/resources/receiving-sessions/pages/mobile-view-receiving-session.blade.php',
            ));
            $this->assertStringContainsString('Mismatch {{ $exceptionBadges[\'mismatch\'] }}', $blade);
            $this->assertStringContainsString('Overage {{ $exceptionBadges[\'overage\'] }}', $blade);
            $this->assertStringContainsString('Wrong site {{ $exceptionBadges[\'wrong_site\'] }}', $blade);
            $this->assertStringNotContainsString('child-epc', $blade);
        } finally {
            $this->cleanup($tenant);
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
            EventParty::query()->whereIn(
                'event_id',
                DB::table('epcis_events')->where('document_id', $this->documentId)->select('id'),
            )->delete();
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
