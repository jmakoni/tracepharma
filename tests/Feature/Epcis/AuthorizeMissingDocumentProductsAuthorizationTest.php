<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\AuthorizeMissingDocumentProducts;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthorizeMissingDocumentProductsAuthorizationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $fdaProductIds = [];

    /** @var list<int> */
    private array $packagingIds = [];

    private ?bool $priorJobRolesEnabled = null;

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function receive_only_user_cannot_authorize_missing_products(): void
    {
        $gtin = $this->uniqueGtin();
        $this->createRxPackaging($gtin);

        $tenant = $this->initializeDemo2Tenant();
        $this->enableJobRoles($tenant);

        $wholesaler = $this->makeWholesalerPartner();
        $document = $this->makeDocumentWithGtins([$gtin]);
        $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);

        $this->actingAs($user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Master data is not authorized for your job role.');

        app(AuthorizeMissingDocumentProducts::class)->handle(
            $document,
            $wholesaler,
            $user,
            alsoResolve: false,
            alsoReprocess: false,
        );
    }

    #[Test]
    public function master_data_user_can_authorize_missing_products_without_exceptions_escalation(): void
    {
        $gtin = $this->uniqueGtin();
        $this->createRxPackaging($gtin);

        $tenant = $this->initializeDemo2Tenant();
        $this->enableJobRoles($tenant);

        $wholesaler = $this->makeWholesalerPartner();
        $document = $this->makeDocumentWithGtins([$gtin]);
        $user = $this->createUserWithRole(TenantRole::MasterDataAdministrator);

        $this->actingAs($user);

        $result = app(AuthorizeMissingDocumentProducts::class)->handle(
            $document,
            $wholesaler,
            $user,
            alsoResolve: false,
            alsoReprocess: false,
        );

        $this->assertSame([$gtin], $result['authorized_gtins']);
    }

    #[Test]
    public function master_data_user_cannot_auto_resolve_without_exceptions_role(): void
    {
        $gtin = $this->uniqueGtin();
        $this->createRxPackaging($gtin);

        $tenant = $this->initializeDemo2Tenant();
        $this->enableJobRoles($tenant);

        $wholesaler = $this->makeWholesalerPartner();
        $document = $this->makeDocumentWithGtins([$gtin]);
        $user = $this->createUserWithRole(TenantRole::MasterDataAdministrator);

        $this->actingAs($user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Exceptions are not authorized for your job role.');

        app(AuthorizeMissingDocumentProducts::class)->handle(
            $document,
            $wholesaler,
            $user,
            alsoResolve: true,
            alsoReprocess: false,
        );
    }

    private function uniqueGtin(): string
    {
        return '030116'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function createRxPackaging(string $gtin): FdaProductPackaging
    {
        $suffix = uniqid();
        $org = FdaOrganization::query()->create([
            'original_name' => 'Auth Gate Labeler '.$suffix,
            'canonical_name' => 'AUTH GATE LABELER '.$suffix,
            'name' => 'Auth Gate Labeler '.$suffix,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        $fda = FdaProduct::query()->create([
            'product_id' => 'AUTH-GATE-'.uniqid(),
            'product_ndc' => fake()->unique()->numerify('#####-###'),
            'brand_name' => 'Auth Gate Rx',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->fdaProductIds[] = (int) $fda->getKey();

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $fda->id,
            'package_ndc' => fake()->unique()->numerify('#####-###-##'),
            'gtin' => $gtin,
            'is_active' => true,
        ]);
        $this->packagingIds[] = (int) $packaging->getKey();

        return $packaging;
    }

    private function makeWholesalerPartner(): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => 'Auth Gate Wholesaler '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    /**
     * @param  list<string>  $gtins
     */
    private function makeDocumentWithGtins(array $gtins): EpcisDocument
    {
        $path = 'epcis/inbound/auth-gate-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'auth-gate.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => false,
            'status' => 'validated',
            'event_count' => 0,
            'epc_count' => count($gtins),
            'received_at' => now(),
            'ingest_generation' => 1,
            'reprocess_count' => 0,
        ]);
        $this->documentIds[] = (int) $document->id;

        $rows = [];
        foreach ($gtins as $index => $gtin) {
            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.'.substr($gtin, -8).'.s'.$index,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => $gtin,
                'serial_number' => 'serial-'.$index,
                'product_id' => null,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            if (Schema::hasTable('document_epcs')) {
                $rows[] = [
                    'document_id' => $document->id,
                    'epc_id' => $epc->id,
                    'ingest_generation' => 1,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('document_epcs')->insert($rows);
        }

        return $document;
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

    private function enableJobRoles(Tenant $tenant): void
    {
        $settings = TenantSettings::forTenant($tenant);
        $this->priorJobRolesEnabled = $settings->jobRolesEnabled();
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $settings->setJobRolesEnabled(true);
        $tenant->save();
    }

    private function createUserWithRole(TenantRole $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);
        $user->refresh();

        return $user;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->priorJobRolesEnabled !== null) {
                $tenant = tenant();
                if ($tenant instanceof Tenant) {
                    TenantSettings::forTenant($tenant)->setJobRolesEnabled($this->priorJobRolesEnabled);
                    $tenant->save();
                }
                $this->priorJobRolesEnabled = null;
            }

            if ($this->documentIds !== [] && Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            }

            if ($this->epcIds !== []) {
                Epc::query()->whereIn('id', $this->epcIds)->delete();
                $this->epcIds = [];
            }

            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                $this->documentIds = [];
            }

            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
                $this->tenantPartnerIds = [];
            }

            tenancy()->end();
        }

        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
            $this->packagingIds = [];
        }

        if ($this->fdaProductIds !== []) {
            FdaProduct::query()->whereIn('id', $this->fdaProductIds)->delete();
            $this->fdaProductIds = [];
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
            $this->orgIds = [];
        }
    }
}
