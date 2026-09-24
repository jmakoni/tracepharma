<?php

namespace Tests\Feature\Vrs;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Jobs\Vrs\RunProductVerificationJob;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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

    /** @var list<int> */
    private array $verificationIds = [];

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
                ->call('confirmScanInput', $uri);

            Bus::assertDispatched(RunProductVerificationJob::class, function (RunProductVerificationJob $job) use ($epc, $user): bool {
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
                ->call('confirmScanInput', $uri);

            Bus::assertNotDispatched(RunProductVerificationJob::class);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function complete_is_blocked_while_vrs_is_pending(): void
    {
        Bus::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);
            $this->forcePharmacyProfile();
            $this->enableReceiveVrsGate();

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.GP'.random_int(10000000, 99999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $confirmed = app(ConfirmReceivingScan::class)->handle($session, $uri, userId: (int) $user->getKey());
            $this->assertTrue($confirmed['ok'] ?? false, (string) ($confirmed['message'] ?? 'confirm failed'));

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('VRS pending');

            app(CompleteReceivingSession::class)->handle(
                ReceivingSession::query()->findOrFail($session->getKey()),
                (int) $user->getKey(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function complete_succeeds_after_vrs_verified(): void
    {
        Bus::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);
            $this->forcePharmacyProfile();
            $this->enableReceiveVrsGate();

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.GV'.random_int(10000000, 99999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $confirmed = app(ConfirmReceivingScan::class)->handle($session, $uri, userId: (int) $user->getKey());
            $this->assertTrue($confirmed['ok'] ?? false, (string) ($confirmed['message'] ?? 'confirm failed'));

            $verification = Verification::query()->create([
                'gtin14' => $epc->gtin14,
                'serial' => $epc->serial_number,
                'status' => 'verified',
                'verified_at' => now(),
                'message' => 'ok',
            ]);
            $this->verificationIds[] = (int) $verification->getKey();

            $completed = app(CompleteReceivingSession::class)->handle(
                ReceivingSession::query()->findOrFail($session->getKey()),
                (int) $user->getKey(),
            );

            $this->assertSame('completed', $completed->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function complete_not_blocked_when_vrs_driver_is_null(): void
    {
        Bus::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'null']);
            TenantSettings::forTenant(tenant())->setHardGateReceiveComplete(true);
            tenant()?->save();
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);
            $this->forcePharmacyProfile();

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.GN'.random_int(10000000, 99999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $confirmed = app(ConfirmReceivingScan::class)->handle($session, $uri, userId: (int) $user->getKey());
            $this->assertTrue($confirmed['ok'] ?? false, (string) ($confirmed['message'] ?? 'confirm failed'));

            $completed = app(CompleteReceivingSession::class)->handle(
                ReceivingSession::query()->findOrFail($session->getKey()),
                (int) $user->getKey(),
            );

            $this->assertSame('completed', $completed->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function complete_is_not_blocked_when_receive_vrs_gate_is_off_by_default(): void
    {
        Bus::fake();

        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->createOwnerUser();
            $this->actingAs($user);
            $this->forcePharmacyProfile();

            $this->assertFalse(TenantSettings::forTenant(tenant())->hardGateReceiveComplete());

            $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.GO'.random_int(10000000, 99999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $confirmed = app(ConfirmReceivingScan::class)->handle($session, $uri, userId: (int) $user->getKey());
            $this->assertTrue($confirmed['ok'] ?? false, (string) ($confirmed['message'] ?? 'confirm failed'));

            $completed = app(CompleteReceivingSession::class)->handle(
                ReceivingSession::query()->findOrFail($session->getKey()),
                (int) $user->getKey(),
            );

            $this->assertSame('completed', $completed->status);
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

    private function forcePharmacyProfile(): void
    {
        $tenant = tenant();
        if ($tenant instanceof Tenant) {
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
        }
    }

    private function enableReceiveVrsGate(): void
    {
        TenantSettings::forTenant(tenant())->setHardGateReceiveComplete(true);
        tenant()?->save();
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
        TenantSettings::forTenant($tenant)->setReceivingEdgeMode(null);
        $tenant->save();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionId !== null) {
                $session = ReceivingSession::query()->find($this->sessionId);
                $authoredId = $session?->receiving_epcis_document_id;
                ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
                ReceivingSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
                if ($authoredId) {
                    $this->documentId = (int) $authoredId;
                }
            }

            if ($this->documentId !== null) {
                $documentId = $this->documentId;
                ReceivingSession::query()->where('epcis_document_id', $documentId)->delete();
                $eventIds = EpcisEvent::query()->where('document_id', $documentId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('document_epcs')->where('document_id', $documentId)->delete();
                EpcisEvent::query()->where('document_id', $documentId)->delete();
                EpcisDocument::query()->whereKey($documentId)->delete();
                $this->documentId = null;
            }

            if ($this->verificationIds !== []) {
                Verification::query()->whereIn('id', $this->verificationIds)->delete();
                $this->verificationIds = [];
            }

            if ($this->epcId !== null) {
                DB::table('document_epcs')->where('epc_id', $this->epcId)->delete();
                DB::table('event_epcs')->where('epc_id', $this->epcId)->delete();
                Epc::query()->whereKey($this->epcId)->delete();
                $this->epcId = null;
            }

            TenantSettings::forTenant(tenant())->setHardGateReceiveComplete(false);
            tenant()?->save();

            tenancy()->end();
        }
    }
}
