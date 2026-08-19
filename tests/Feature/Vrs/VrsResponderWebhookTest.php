<?php

namespace Tests\Feature\Vrs;

use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Models\Verification;
use App\Services\Quarantine\QuarantineService;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VrsResponderWebhookTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const RESPONDER_KEY = 'test-vrs-responder-key';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<string> */
    private array $extraTenantIds = [];

    private bool $clearedTenantResponderKey = false;

    #[Test]
    public function responder_verifies_known_serial(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenantResponderKey($tenant);

        try {
            $uri = 'urn:epc:id:sgtin:030116.0200116.RESP'.random_int(100000, 999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            tenancy()->end();

            $response = $this->postJson(
                '/api/webhooks/vrs/'.self::DEMO2_TENANT_ID,
                [
                    'gtin14' => $epc->gtin14,
                    'serial' => $epc->serial_number,
                ],
                ['X-Vrs-Api-Key' => self::RESPONDER_KEY],
            );

            $response->assertOk()
                ->assertJson([
                    'status' => 'verified',
                    'found' => true,
                    'gtin14' => $epc->gtin14,
                    'serial' => $epc->serial_number,
                ]);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->verificationIds[] = (int) $response->json('verification_id');
            $verification = Verification::query()->find($response->json('verification_id'));
            $this->assertNotNull($verification);
            $this->assertSame('responder', $verification->request_payload['source'] ?? null);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function responder_returns_not_found_for_unknown_serial(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenantResponderKey($tenant);

        try {
            tenancy()->end();

            $response = $this->postJson(
                '/api/webhooks/vrs/'.self::DEMO2_TENANT_ID,
                [
                    'gtin14' => '30301164005162',
                    'serial' => 'UNKNOWN-SERIAL-'.random_int(1000, 9999),
                ],
                ['X-Vrs-Api-Key' => self::RESPONDER_KEY],
            );

            $response->assertNotFound()
                ->assertJson([
                    'status' => 'failed',
                    'found' => false,
                ]);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->verificationIds[] = (int) $response->json('verification_id');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function responder_never_verifies_quarantined_serial(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenantResponderKey($tenant);

        try {
            $uri = 'urn:epc:id:sgtin:030116.0200116.QR'.random_int(100000, 999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [(int) $epc->getKey()],
                reason: 'Quarantine blocks inbound VRS verify',
            );
            $this->caseIds[] = (int) $case->getKey();

            tenancy()->end();

            $response = $this->postJson(
                '/api/webhooks/vrs/'.self::DEMO2_TENANT_ID,
                [
                    'gtin14' => $epc->gtin14,
                    'serial' => $epc->serial_number,
                ],
                ['X-Vrs-Api-Key' => self::RESPONDER_KEY],
            );

            $response->assertOk()
                ->assertJson([
                    'status' => 'suspect',
                    'found' => true,
                ])
                ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Under quarantine'));

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->verificationIds[] = (int) $response->json('verification_id');
            $verification = Verification::query()->find($response->json('verification_id'));
            $this->assertNotNull($verification);
            $this->assertNotSame('verified', $verification->status);
            $this->assertNull($verification->verified_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function responder_never_verifies_decommissioned_serial(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenantResponderKey($tenant);

        try {
            $uri = 'urn:epc:id:sgtin:030116.0200116.DC'.random_int(100000, 999999);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();
            $this->authorTerminalDispositionEvent($epc);

            tenancy()->end();

            $response = $this->postJson(
                '/api/webhooks/vrs/'.self::DEMO2_TENANT_ID,
                [
                    'gtin14' => $epc->gtin14,
                    'serial' => $epc->serial_number,
                ],
                ['X-Vrs-Api-Key' => self::RESPONDER_KEY],
            );

            $response->assertOk()
                ->assertJson([
                    'status' => 'failed',
                    'found' => true,
                ])
                ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'cannot be verified'));

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->verificationIds[] = (int) $response->json('verification_id');
            $verification = Verification::query()->find($response->json('verification_id'));
            $this->assertNotNull($verification);
            $this->assertNotSame('verified', $verification->status);
            $this->assertNull($verification->verified_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function responder_rejects_invalid_api_key(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenantResponderKey($tenant);

        try {
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/vrs/'.self::DEMO2_TENANT_ID,
                ['gtin14' => '30301164005162', 'serial' => 'X'],
                ['X-Vrs-Api-Key' => 'wrong-key'],
            )->assertUnauthorized();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function responder_rejects_tenant_a_key_against_tenant_b(): void
    {
        $tenantA = $this->initializeDemo2Tenant();
        $tenantBId = (string) \Illuminate\Support\Str::uuid();
        $tenantB = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => $tenantBId,
            'name' => 'VRS Responder Tenant B',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_vrs_responder_b_'.str_replace('-', '', $tenantBId),
        ]));
        $this->extraTenantIds[] = $tenantBId;

        try {
            config(['vrs.responder.api_key' => null]);

            TenantSettings::forTenant($tenantA)->setVrsResponderApiKey('tenant-a-vrs-key');
            $tenantA->save();

            TenantSettings::forTenant($tenantB)->setVrsResponderApiKey('tenant-b-vrs-key');
            $tenantB->save();

            tenancy()->end();

            $this->postJson(
                '/api/webhooks/vrs/'.$tenantBId,
                ['gtin14' => '30301164005162', 'serial' => 'X'],
                ['X-Vrs-Api-Key' => 'tenant-a-vrs-key'],
            )->assertUnauthorized();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function production_rejects_missing_tenant_responder_key(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setVrsResponderApiKey(null);
            $tenant->save();
            $this->clearedTenantResponderKey = true;

            config(['vrs.responder.api_key' => self::RESPONDER_KEY]);
            $this->app->detectEnvironment(fn () => 'production');
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/vrs/'.self::DEMO2_TENANT_ID,
                ['gtin14' => '30301164005162', 'serial' => 'X'],
                ['X-Vrs-Api-Key' => self::RESPONDER_KEY],
            )->assertStatus(503);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
            $this->cleanup();
        }
    }

    #[Test]
    public function non_production_rejects_missing_tenant_responder_key(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setVrsResponderApiKey(null);
            $tenant->save();
            $this->clearedTenantResponderKey = true;

            config(['vrs.responder.api_key' => self::RESPONDER_KEY]);
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/vrs/'.self::DEMO2_TENANT_ID,
                ['gtin14' => '30301164005162', 'serial' => 'UNKNOWN-'.random_int(1000, 9999)],
                ['X-Vrs-Api-Key' => self::RESPONDER_KEY],
            )->assertStatus(503);
        } finally {
            $this->cleanup();
        }
    }

    private function configureTenantResponderKey(Tenant $tenant, string $key = self::RESPONDER_KEY): void
    {
        TenantSettings::forTenant($tenant)->setVrsResponderApiKey($key);
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

        tenancy()->initialize($tenant);

        $this->seed(ExceptionCaseSeeder::class);

        return $tenant;
    }

    private function authorTerminalDispositionEvent(Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'vrs-responder-terminal-'.Str::random(6).'.xml',
            'notes' => 'Terminal disposition for inbound VRS responder test.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:decommissioning',
            'disposition' => 'urn:epcglobal:cbv:disp:inactive',
            'read_point_gln' => '0366159000034',
            'biz_location_gln' => '0366159000034',
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function cleanup(): void
    {
        if ($this->clearedTenantResponderKey) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                TenantSettings::forTenant($tenant)->setVrsResponderApiKey(null);
                $tenant->save();
            }
        }

        foreach ($this->extraTenantIds as $tenantId) {
            Tenant::withoutEvents(fn () => Tenant::query()->whereKey($tenantId)->delete());
        }
        $this->extraTenantIds = [];

        if (! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (tenancy()->initialized) {
            if ($this->verificationIds !== []) {
                Verification::query()->whereKey($this->verificationIds)->delete();
                $this->verificationIds = [];
            }

            foreach ($this->caseIds as $caseId) {
                \App\Models\Quarantine\QuarantineHold::query()->where('exception_id', $caseId)->delete();
                \App\Models\Exceptions\ExceptionCase::query()->whereKey($caseId)->delete();
            }
            $this->caseIds = [];

            if ($this->eventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
                EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
                $this->eventIds = [];
            }

            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                $this->documentIds = [];
            }

            if ($this->epcIds !== []) {
                Epc::query()->whereKey($this->epcIds)->delete();
                $this->epcIds = [];
            }
            tenancy()->end();
        }

        $this->clearedTenantResponderKey = false;
    }
}
