<?php

namespace Tests\Feature\Vrs;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Jobs\Vrs\RunProductVerificationJob;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiveQueueVerificationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private ?int $sessionId = null;

    private ?int $documentId = null;

    private ?int $epcId = null;

    private static bool $demo2TenantReady = false;

    #[Test]
    public function staged_sgtin_confirm_dispatches_vrs_job_when_driver_configured(): void
    {
        Bus::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.VQ'.random_int(10000000, 99999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->call('stageScan', $uri)
                ->call('confirmStagedScans');

            Bus::assertDispatched(RunProductVerificationJob::class, function (RunProductVerificationJob $job) use ($uri, $epc, $user): bool {
                return $job->tenantId === self::DEMO2_TENANT_ID
                    && $job->actorId === (int) $user->getKey()
                    && str_contains($job->scan, (string) $epc->gtin14)
                    && str_contains($job->scan, (string) $epc->serial_number);
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function desktop_sgtin_confirm_dispatches_vrs_job_when_driver_configured(): void
    {
        Bus::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.DT'.random_int(10000000, 99999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->set('scan', $uri)
                ->callAction('confirmScan');

            Bus::assertDispatched(RunProductVerificationJob::class);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sscc_confirm_does_not_dispatch_vrs_job(): void
    {
        Bus::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->set('scan', self::SSCC_URI)
                ->callAction('confirmScan');

            Bus::assertNotDispatched(RunProductVerificationJob::class);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function null_vrs_driver_does_not_dispatch_job_on_sgtin_confirm(): void
    {
        Bus::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'null']);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.ND'.random_int(10000000, 99999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            Livewire::test(MobileViewReceivingSession::class, ['record' => $session->getKey()])
                ->call('stageScan', $uri)
                ->call('confirmStagedScans');

            Bus::assertNotDispatched(RunProductVerificationJob::class);
        } finally {
            $this->cleanup();
        }
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
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

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'vrs-queue-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);

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

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionId !== null) {
                ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
                ReceivingSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            if ($this->documentId !== null) {
                $documentId = $this->documentId;
                ReceivingSession::query()->where('epcis_document_id', $documentId)->delete();
                EpcisDocument::query()->whereKey($documentId)->delete();
                $this->documentId = null;
            }

            if ($this->epcId !== null) {
                Epc::query()->whereKey($this->epcId)->delete();
                $this->epcId = null;
            }

            tenancy()->end();
        }
    }
}
