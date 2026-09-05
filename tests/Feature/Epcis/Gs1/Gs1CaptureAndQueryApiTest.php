<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis\Gs1;

use App\Actions\Epcis\ValidateEpcis12Document;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\SanctumAbilities;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class Gs1CaptureAndQueryApiTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function capture_accepts_xml_and_returns_capture_id(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Queue::fake();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('capture', [
                SanctumAbilities::EPCIS_UPLOAD,
                SanctumAbilities::EPCIS_VIEW,
            ])->plainTextToken;

            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');
            tenancy()->end();

            $response = $this->tenantApiPost('/api/v1/epcis/capture', $token, $xml, [
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_X-Original-Filename' => 'gs1-capture.xml',
            ]);

            $response->assertAccepted()
                ->assertJsonPath('type', 'CaptureAccepted')
                ->assertJsonStructure(['captureID', 'status', 'document_uuid']);

            $captureId = (int) $response->json('captureID');
            $this->trackEpcisDocumentId($captureId);
            $this->assertNotEmpty($response->headers->get('Location'));

            $status = $this->tenantApiGet('/api/v1/epcis/capture/'.$captureId, $token);
            $status->assertOk()->assertJsonPath('captureID', (string) $captureId);
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function events_query_filters_by_biz_step(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Queue::fake();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('query', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            $uniqueEpcUri = 'urn:epc:id:sgtin:0614141.107346.'.substr(str_replace('-', '', (string) str()->uuid()), 0, 12);

            $doc = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'status' => 'validated',
                'ingest_generation' => 1,
                'event_count' => 1,
                'received_at' => now(),
            ]);
            $this->trackEpcisDocumentId((int) $doc->getKey());

            $event = EpcisEvent::query()->create([
                'document_id' => $doc->getKey(),
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'event_timezone_offset' => '+00:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
                'event_id' => 'urn:uuid:'.str()->uuid(),
            ]);

            $epc = \App\Models\Epcis\Epc::query()->create(
                \App\Models\Epcis\Epc::materializeAttributesFromUri($uniqueEpcUri),
            );
            \App\Models\Epcis\EventEpc::query()->create([
                'event_id' => $event->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ]);

            EpcisEvent::query()->create([
                'document_id' => $doc->getKey(),
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'event_timezone_offset' => '+00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'disposition' => 'urn:epcglobal:cbv:disp:active',
                'event_id' => 'urn:uuid:'.str()->uuid(),
            ]);

            tenancy()->end();

            $response = $this->tenantApiGet(
                '/api/v1/epcis/events?EQ_bizStep='.urlencode('urn:epcglobal:cbv:bizstep:shipping')
                .'&MATCH_epc='.urlencode($uniqueEpcUri),
                $token,
            );

            $response->assertOk()
                ->assertJsonPath('type', 'EPCISQueryDocument');

            $events = $response->json('epcisBody.queryResults.resultBody.eventList');
            $this->assertIsArray($events);
            $this->assertCount(1, $events);
            $this->assertSame('urn:epcglobal:cbv:bizstep:shipping', $events[0]['bizStep']);

            $show = $this->tenantApiGet('/api/v1/epcis/events/'.$event->getKey(), $token);
            $show->assertOk()->assertJsonPath('bizStep', 'urn:epcglobal:cbv:bizstep:shipping');
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function events_query_rejects_unknown_params(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $token = $user->createToken('query', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;
            tenancy()->end();

            $this->tenantApiGet('/api/v1/epcis/events?FOO_bar=1', $token)
                ->assertStatus(422)
                ->assertJsonPath('type', 'QueryParameterException');
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function capture_and_query_respect_kill_switch(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setKillSwitch(TenantKillSwitches::INBOUND_EPCIS, true);
            $tenant->save();

            $user = User::factory()->create();
            $token = $user->createToken('ks', [
                SanctumAbilities::EPCIS_UPLOAD,
                SanctumAbilities::EPCIS_VIEW,
            ])->plainTextToken;
            tenancy()->end();

            $this->tenantApiGet('/api/v1/epcis/events', $token)
                ->assertForbidden()
                ->assertJsonPath('type', 'SecurityException');

            $this->tenantApiPost('/api/v1/epcis/capture', $token, '<xml/>', [
                'CONTENT_TYPE' => 'application/xml',
            ])->assertForbidden();
        } finally {
            TenantSettings::forTenant($tenant)->setKillSwitch(TenantKillSwitches::INBOUND_EPCIS, false);
            $tenant->save();
            if (tenancy()->initialized) {
                tenancy()->end();
            }
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

    private function uniqueFixtureXml(string $fixturePath): string
    {
        $xml = file_get_contents(base_path($fixturePath));
        $this->assertNotFalse($xml);

        return str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
    }

    private function tenantApiPost(string $uri, ?string $token, string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;
        $server = array_merge([
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/xml',
        ], $headers);
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('POST', $absolute, [], [], [], $server, $body);
    }

    private function tenantApiGet(string $uri, ?string $token): \Illuminate\Testing\TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;
        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
        ];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('GET', $absolute, [], [], [], $server);
    }
}
