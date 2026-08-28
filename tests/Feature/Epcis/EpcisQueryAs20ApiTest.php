<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\EpcisDocument;
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

class EpcisQueryAs20ApiTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function query_as_20_returns_json_ld_from_1_2_xml_origin(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('epcis-query', [
                SanctumAbilities::EPCIS_UPLOAD,
                SanctumAbilities::EPCIS_VIEW,
            ])->plainTextToken;

            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $upload = $this->tenantApiPost('/api/v1/epcis/inbound', $token, $xml, [
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_X-Original-Filename' => 'query-as-20.xml',
            ]);
            $upload->assertAccepted();
            $documentId = (int) $upload->json('document_id');
            $this->trackEpcisDocumentId($documentId);

            tenancy()->initialize($tenant);
            $document = EpcisDocument::query()->findOrFail($documentId);
            if ($document->status === 'received') {
                app(EpcisIngestionService::class)->process($document);
                app(\App\Actions\Epcis\ValidateEpcis12Document::class)->handle($document->refresh());
            } elseif ($document->status === 'parsed') {
                app(\App\Actions\Epcis\ValidateEpcis12Document::class)->handle($document);
            }
            $this->assertSame('validated', $document->refresh()->status);
            tenancy()->end();

            $show = $this->tenantApiGet("/api/v1/epcis/documents/{$documentId}", $token);
            $show->assertOk()
                ->assertJsonPath('data.schema_version', '1.2')
                ->assertJsonPath('data.format', 'xml')
                ->assertJsonPath('data.status', 'validated');

            $jsonLd = $this->tenantApiGet("/api/v1/epcis/documents/{$documentId}/epcis-2.0", $token);
            $jsonLd->assertOk()
                ->assertJsonPath('type', 'EPCISDocument')
                ->assertJsonPath('schemaVersion', '2.0');

            $events = $jsonLd->json('epcisBody.eventList');
            $this->assertIsArray($events);
            $this->assertNotEmpty($events);
            $this->assertSame(
                'urn:epcglobal:cbv:bizstep:commissioning',
                $events[0]['bizStep'],
            );
        } finally {
            $this->cleanupTrackedEpcisArtifacts();
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function query_as_20_rejects_kill_switch(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setKillSwitch(TenantKillSwitches::INBOUND_EPCIS, true);
            $tenant->save();

            $user = User::factory()->create();
            $token = $user->createToken('epcis-query', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $this->tenantApiGet('/api/v1/epcis/documents', $token)->assertForbidden();
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
