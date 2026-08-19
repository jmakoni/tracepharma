<?php

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\SanctumAbilities;
use App\Support\TenantSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class EpcisApiTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function inbound_accepts_raw_xml_and_returns_document_metadata(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['queue.default' => 'database']);
            Queue::fake();

            $user = User::factory()->create();
            $token = $user->createToken('epcis-test', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $response = $this->tenantApiPost('/api/v1/epcis/inbound', $token, $xml, [
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_X-Original-Filename' => 'minimal_object_shipping.xml',
            ]);
            $this->trackInboundDocumentIfPresent($response);

            $response->assertAccepted()
                ->assertJson([
                    'message' => 'EPCIS document accepted for processing.',
                    'status' => 'received',
                ])
                ->assertJsonStructure([
                    'message',
                    'document_id',
                    'document_uuid',
                    'status',
                ]);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $document = EpcisDocument::query()->find($response->json('document_id'));
            $this->assertNotNull($document);
            $this->assertSame('api', $document->received_via?->value);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_accepts_multipart_file_upload(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['queue.default' => 'database']);
            Queue::fake();

            $user = User::factory()->create();
            $token = $user->createToken('epcis-test', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $file = UploadedFile::fake()->createWithContent('minimal_object_shipping.xml', $xml);

            tenancy()->end();

            $response = $this->tenantApiMultipartPost('/api/v1/epcis/inbound', $token, [
                'file' => $file,
            ]);
            $this->trackInboundDocumentIfPresent($response);

            $response->assertAccepted()
                ->assertJsonPath('status', 'received');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_returns_duplicate_response_for_same_payload(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['queue.default' => 'database']);
            Queue::fake();

            $user = User::factory()->create();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('epcis-test', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $first = $this->tenantApiPost('/api/v1/epcis/inbound', $token, $xml, [
                'CONTENT_TYPE' => 'application/xml',
            ]);
            $this->trackInboundDocumentIfPresent($first);
            $first->assertAccepted();

            $duplicate = $this->tenantApiPost('/api/v1/epcis/inbound', $token, $xml, [
                'CONTENT_TYPE' => 'application/xml',
            ]);

            $duplicate->assertStatus(409)
                ->assertJson([
                    'message' => 'EPCIS document already received.',
                    'document_id' => $first->json('document_id'),
                    'document_uuid' => $first->json('document_uuid'),
                    'duplicate' => true,
                ]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_requires_epcis_upload_ability(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $token = $user->createToken('epcis-test', ['view'])->plainTextToken;

            tenancy()->end();

            $this->tenantApiPost('/api/v1/epcis/inbound', $token, '<epcis/>', [
                'CONTENT_TYPE' => 'application/xml',
            ])->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_requires_authentication(): void
    {
        $this->initializeDemo2Tenant();

        try {
            tenancy()->end();

            $this->tenantApiPost('/api/v1/epcis/inbound', null, '<epcis/>', [
                'CONTENT_TYPE' => 'application/xml',
            ])->assertUnauthorized();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function documents_lists_paginated_inbound_catalog_rows(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['queue.default' => 'database']);
            Queue::fake();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('epcis-test', [
                SanctumAbilities::EPCIS_UPLOAD,
                SanctumAbilities::EPCIS_VIEW,
            ])->plainTextToken;
            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');

            tenancy()->end();

            $upload = $this->tenantApiPost('/api/v1/epcis/inbound', $token, $xml, [
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_X-Original-Filename' => 'listed_document.xml',
            ]);
            $this->trackInboundDocumentIfPresent($upload);
            $upload->assertAccepted();

            $response = $this->tenantApiGet('/api/v1/epcis/documents?per_page=10', $token);

            $response->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'uuid',
                            'status',
                            'original_filename',
                            'received_at',
                            'received_via',
                        ],
                    ],
                    'meta' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                    ],
                ])
                ->assertJsonPath('meta.per_page', 10);

            $uuids = collect($response->json('data'))->pluck('uuid');
            $this->assertTrue($uuids->contains($upload->json('document_uuid')));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function documents_accepts_epcis_view_ability(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $token = $user->createToken('epcis-view', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $this->tenantApiGet('/api/v1/epcis/documents', $token)->assertOk();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function documents_rejects_generic_view_ability(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $token = $user->createToken('epcis-view', ['view'])->plainTextToken;

            tenancy()->end();

            $this->tenantApiGet('/api/v1/epcis/documents', $token)->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function documents_requires_view_ability(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $token = $user->createToken('epcis-test', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;

            tenancy()->end();

            $this->tenantApiGet('/api/v1/epcis/documents', $token)->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function documents_requires_nav_receive_or_integrations_when_job_roles_are_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            $user = User::factory()->create();
            $user->syncRoles([TenantRole::VrsAnalyst->value]);
            $token = $user->createToken('epcis-view', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $this->tenantApiGet('/api/v1/epcis/documents', $token)->assertForbidden();
        } finally {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_requires_nav_integrations_when_job_roles_are_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            $user = User::factory()->create();
            $user->syncRoles([TenantRole::ReceivingTechnician->value]);
            $token = $user->createToken('epcis-test', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;

            tenancy()->end();

            $this->tenantApiPost('/api/v1/epcis/inbound', $token, '<epcis/>', [
                'CONTENT_TYPE' => 'application/xml',
            ])->assertForbidden();
        } finally {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_store_rejects_ship_to_site_outside_user_scope(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['queue.default' => 'database']);
            Queue::fake();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $siteAGln = $this->uniqueGln();
            $siteBGln = $this->uniqueGln();

            $siteA = Site::query()->create([
                'name' => 'Inbound API Site A '.Str::random(6),
                'gln' => $siteAGln,
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $siteB = Site::query()->create([
                'name' => 'Inbound API Site B '.Str::random(6),
                'gln' => $siteBGln,
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

            $user = User::factory()->create();
            $user->syncSites([(int) $siteA->getKey()]);
            $this->userIds[] = (int) $user->getKey();
            $token = $user->createToken('epcis-test', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;

            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $xml = str_replace('0096295000009', $siteBGln, $xml);

            tenancy()->end();

            $this->tenantApiPost('/api/v1/epcis/inbound', $token, $xml, [
                'CONTENT_TYPE' => 'application/xml',
            ])->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_duplicate_out_of_scope_returns_generic_409(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['queue.default' => 'database']);
            Queue::fake();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $siteAGln = $this->uniqueGln();
            $siteBGln = $this->uniqueGln();

            $siteA = Site::query()->create([
                'name' => 'Inbound Dup Site A '.Str::random(6),
                'gln' => $siteAGln,
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $siteB = Site::query()->create([
                'name' => 'Inbound Dup Site B '.Str::random(6),
                'gln' => $siteBGln,
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $ownerToken = $owner->createToken('epcis-owner', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;
            $this->userIds[] = (int) $owner->getKey();

            $restricted = User::factory()->create();
            $restricted->syncSites([(int) $siteA->getKey()]);
            $restrictedToken = $restricted->createToken('epcis-restricted', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;
            $this->userIds[] = (int) $restricted->getKey();

            $xml = $this->uniqueFixtureXml('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $xml = str_replace('0096295000009', $siteBGln, $xml);

            tenancy()->end();

            $first = $this->tenantApiPost('/api/v1/epcis/inbound', $ownerToken, $xml, [
                'CONTENT_TYPE' => 'application/xml',
            ]);
            $this->trackInboundDocumentIfPresent($first);
            $first->assertAccepted();

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            EpcisDocument::query()
                ->whereKey($first->json('document_id'))
                ->update(['ship_to_site_id' => (int) $siteB->getKey()]);
            tenancy()->end();

            $duplicate = $this->tenantApiPost('/api/v1/epcis/inbound', $restrictedToken, $xml, [
                'CONTENT_TYPE' => 'application/xml',
            ]);

            $duplicate->assertStatus(409)
                ->assertJson([
                    'message' => 'EPCIS document already received.',
                    'duplicate' => true,
                ])
                ->assertJsonMissing(['document_id'])
                ->assertJsonMissing(['document_uuid'])
                ->assertJsonMissing(['status']);

        } finally {
            $this->cleanup();
        }
    }

    private function trackInboundDocumentIfPresent(\Illuminate\Testing\TestResponse $response): void
    {
        $id = $response->json('document_id');
        if (is_numeric($id)) {
            $this->trackEpcisDocumentId((int) $id);
        }
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.\App\Support\Gs1\Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function uniqueFixtureXml(string $relativePath, string $uuidPlaceholder = '11111111-2222-3333-4444-555555555555'): string
    {
        $fixture = base_path($relativePath);
        $this->assertFileExists($fixture);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);

        return str_replace($uuidPlaceholder, (string) str()->uuid(), $xml);
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
        if (! tenancy()->initialized) {
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
        }

        $this->cleanupTrackedEpcisArtifacts();

        if ($this->userIds !== []) {
            User::query()->whereKey($this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereKey($this->siteIds)->delete();
            $this->siteIds = [];
        }

        tenancy()->end();
    }

    /**
     * @param  array<string, string>  $extraServer
     */
    private function tenantApiPost(string $uri, ?string $token, string $body, array $extraServer = []): \Illuminate\Testing\TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = array_merge([
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
        ], $extraServer);

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call(
            'POST',
            $absolute,
            [],
            [],
            [],
            $server,
            $body,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tenantApiMultipartPost(string $uri, ?string $token, array $data): \Illuminate\Testing\TestResponse
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

        return $this->call(
            'POST',
            $absolute,
            [],
            [],
            $data,
            $server,
        );
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

        return $this->call(
            'GET',
            $absolute,
            [],
            [],
            [],
            $server,
        );
    }
}
