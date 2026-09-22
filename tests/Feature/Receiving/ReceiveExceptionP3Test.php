<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\DeleteReceivingSession;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Vrs\RunProductVerification;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\Verification;
use App\Services\Vrs\Contracts\VrsClient;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceiveExceptionTypes;
use App\Support\Receiving\ReceiveSessionExceptionQuery;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionTypeSeeder;
use DomainException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ReceiveExceptionP3Test extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $extraEpcIds = [];

    /** @var list<int> */
    private array $verificationIds = [];

    private ?bool $priorRequireTi = null;

    private ?bool $priorAllowWithoutFile = null;

    private ?bool $priorHardGate = null;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function verify_fail_during_open_receive_links_case_shows_quarantine_badge_and_blocks_complete(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            Bus::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setHardGateReceiveComplete(true);
            $tenant->save();
            config(['vrs.driver' => 'fake']);

            $session = $this->openAsnFromFixture();
            $policy = ReceivingPolicy::forTenant($tenant);
            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $epc = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $this->bindFailedVrsClient((string) $epc->gtin14, (string) $epc->serial_number);

            $scan = ElementString::encodeSgtin((string) $epc->gtin14, (string) $epc->serial_number);
            $result = app(RunProductVerification::class)->handle($scan);
            $this->assertNotNull($result['exception_id']);
            $this->verificationIds[] = (int) $result['verification']->getKey();

            $case = ExceptionCase::query()->findOrFail($result['exception_id']);
            $this->trackCase($case);
            $this->assertSame('VERIFICATION_FAILED', $case->type?->code);
            $this->assertTrue($case->epcs()->whereKey($epc->getKey())->exists());
            $this->assertTrue(
                $case->activities()
                    ->where('meta->receiving_session_id', (int) $session->getKey())
                    ->where('meta->epc_id', (int) $epc->getKey())
                    ->exists(),
                'Verify case must carry receiving_session_id and epc_id for the open receive.',
            );
            $this->assertTrue(
                QuarantineHold::query()
                    ->open()
                    ->where('exception_id', $case->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->where(function ($query) use ($session): void {
                        $query->where('meta->receiving_session_id', (int) $session->getKey())
                            ->orWhere('meta->receiving_session_id', (string) $session->getKey());
                    })
                    ->exists(),
            );

            $badges = ReceiveSessionExceptionQuery::floorBadgeCounts($session->fresh());
            $this->assertGreaterThanOrEqual(1, $badges['quarantine']);
            $this->assertSame(
                0,
                ReceiveSessionExceptionQuery::openCases($session, ReceiveExceptionTypes::HARD_BLOCK_COMPLETE)->count(),
                'P3 must not author a new receive exception type.',
            );

            try {
                app(CompleteReceivingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException while the serial is still quarantined.');
            } catch (DomainException $e) {
                $this->assertStringContainsStringIgnoringCase('quarantine', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function verify_fail_with_no_receive_session_leaves_receiving_session_id_unset(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            config(['vrs.driver' => 'fake']);

            $serial = 'FAIL-P3-NO-RECV-'.Str::lower((string) Str::ulid());
            $result = app(RunProductVerification::class)->handle('(01)30301164005162(21)'.$serial);
            $this->assertNotNull($result['exception_id']);
            $this->verificationIds[] = (int) $result['verification']->getKey();

            $case = ExceptionCase::query()->findOrFail($result['exception_id']);
            $this->trackCase($case);
            $this->assertSame('VERIFICATION_FAILED', $case->type?->code);
            $this->assertFalse(
                $case->activities()->whereNotNull('meta->receiving_session_id')->exists(),
                'Verify fail off a receive session must not stamp receiving_session_id.',
            );
            $this->assertFalse(
                QuarantineHold::query()
                    ->open()
                    ->where('exception_id', $case->getKey())
                    ->whereNotNull('meta->receiving_session_id')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function floor_cancel_still_authors_refused_and_extra_serial_is_still_overage(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $cancelled = $this->openAsnFromFixture();
            app(CancelReceivingSession::class)->handle(
                $cancelled,
                null,
                'Floor cancel after product presented.',
            );
            $refused = ReceiveSessionExceptionQuery::openCases($cancelled, [ReceiveExceptionTypes::REFUSED])->first();
            $this->assertNotNull($refused);
            $this->trackCase($refused);

            $session = $this->openAsnFromFixture();
            $sessionId = (int) $session->getKey();
            app(DeleteReceivingSession::class)->handle($session, null, 'Floor cancel deleted the session.');
            $this->assertNull(ReceivingSession::query()->find($sessionId));
            $floorRefused = ExceptionCase::query()
                ->whereHas('type', fn ($types) => $types->where('code', ReceiveExceptionTypes::REFUSED))
                ->whereHas('activities', function ($activities) use ($sessionId): void {
                    $activities->where('meta->receiving_session_id', $sessionId)
                        ->orWhere('meta->receiving_session_id', (string) $sessionId);
                })
                ->latest('id')
                ->first();
            $this->assertNotNull($floorRefused);
            $this->trackCase($floorRefused);

            $overageSession = $this->openAsnFromFixture();
            $extra = $this->createSsccEpc();
            $extraResult = app(ConfirmReceivingScan::class)->handle(
                $overageSession,
                $extra->epc_uri,
                null,
                false,
            );
            $this->assertFalse($extraResult['ok']);
            $overage = ReceiveSessionExceptionQuery::openCases(
                $overageSession,
                [ReceiveExceptionTypes::OVERAGE],
            )->first();
            $this->assertNotNull($overage);
            $this->trackCase($overage);
            $this->assertTrue($overage->epcs()->whereKey($extra->getKey())->exists());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function floor_receive_reuses_quarantine_badge_copy(): void
    {
        $mobile = file_get_contents(resource_path(
            'views/filament/app/resources/receiving-sessions/pages/mobile-view-receiving-session.blade.php',
        ));
        $this->assertNotFalse($mobile);
        $this->assertStringContainsString("Quarantine {{ \$exceptionBadges['quarantine'] }}", $mobile);
        $this->assertStringNotContainsString('VRS {{', $mobile);
        $this->assertStringNotContainsString('Verification {{', $mobile);
    }

    private function bindFailedVrsClient(string $gtin14, string $serial): void
    {
        $this->app->bind(VrsClient::class, fn (): VrsClient => new class($gtin14, $serial) implements VrsClient
        {
            public function __construct(
                private readonly string $gtin14,
                private readonly string $serial,
            ) {}

            public function verify(
                string $gtin14,
                string $serial,
                ?string $lot = null,
                ?string $expiryYymmdd = null,
            ): array {
                return [
                    'gtin14' => $this->gtin14,
                    'serial' => $this->serial,
                    'lot' => $lot,
                    'expiry_yymmdd' => $expiryYymmdd,
                    'status' => 'failed',
                    'message' => 'GTIN and serial do not match manufacturer records.',
                ];
            }
        });
    }

    private function trackCase(ExceptionCase $case): void
    {
        $this->caseIds[] = (int) $case->getKey();
    }

    private function openAsnFromFixture(): ReceivingSession
    {
        $document = $this->ingestMinimalFixture();
        $this->documentIds[] = (int) $document->getKey();
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
        $this->priorHardGate = $settings->hardGateReceiveComplete();
        $this->priorEdgeMode = $settings->receivingEdgeMode();

        app(ExceptionTypeSeeder::class)->run();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->verificationIds !== []) {
            Verification::query()->whereIn('id', $this->verificationIds)->delete();
            $this->verificationIds = [];
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

        if ($this->documentIds !== []) {
            ReceivingScanLine::query()
                ->whereIn(
                    'receiving_session_id',
                    ReceivingSession::query()->whereIn('epcis_document_id', $this->documentIds)->select('id'),
                )
                ->delete();
            ReceivingSession::query()->whereIn('epcis_document_id', $this->documentIds)->delete();
            DB::table('event_epcs')->whereIn(
                'event_id',
                DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->select('id'),
            )->delete();
            DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
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
        if ($this->priorHardGate !== null) {
            $settings->setHardGateReceiveComplete($this->priorHardGate);
        }
        $settings->setReceivingEdgeMode($this->priorEdgeMode);
        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile]);
        }
        $tenant->save();
        $this->priorRequireTi = null;
        $this->priorAllowWithoutFile = null;
        $this->priorHardGate = null;
        $this->priorEdgeMode = null;
        $this->priorProfile = null;

        tenancy()->end();
    }
}
