<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\RecordDestinationGlnMismatch;
use App\Actions\Epcis\RecordOperationalEpcisException;
use App\Actions\Exceptions\SyncDestinationGlnMismatchReceiveImpact;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\Receiving\ReceivingGate;
use App\Support\Custody\TenantGlnSet;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordDestinationGlnMismatchTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const TENANT_GLN = '1200202228045';

    private const FOREIGN_SOLD_TO = '9998887776665';

    private const FOREIGN_SHIP_TO = '9998887776658';

    private static bool $demo2TenantReady = false;

    private ?string $originalTenantGln = null;

    private ?string $originalCompanyPrefix = null;

    private ?TenantProfile $originalProfile = null;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Promoting DESTINATION_* signals opens cases that dispatch mail; fake so
        // setting-on tests do not wait on real notification delivery.
        Notification::fake();
    }

    #[Test]
    public function handle_does_not_mass_promote_existing_open_destination_signals(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureTenantGlnSet($tenant, self::TENANT_GLN);
            $this->setProfile(tenant(), TenantProfile::Pharmacy);

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::FOREIGN_SOLD_TO,
                'ship_to_gln' => self::FOREIGN_SHIP_TO,
            ]);

            app(RecordDestinationGlnMismatch::class)->handle($document);

            $openWithoutCase = EpcisException::query()
                ->where('document_id', $document->getKey())
                ->whereIn('exception_type', [
                    RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
                    RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
                ])
                ->where('status', 'open')
                ->whereNull('case_id')
                ->count();

            $this->assertGreaterThan(0, $openWithoutCase);

            TenantSettings::forTenant(tenant())->setBlockReceiveOnDestinationGlnMismatch(false);
            app(SyncDestinationGlnMismatchReceiveImpact::class)->handle(true);

            $this->assertSame(
                $openWithoutCase,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->whereIn('exception_type', [
                        RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
                        RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
                    ])
                    ->where('status', 'open')
                    ->whereNull('case_id')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pharmacy_raises_owning_party_and_location_mismatches_for_foreign_glns(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureTenantGlnSet($tenant, self::TENANT_GLN);
            $tenant = tenant();
            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $tenant = tenant();

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::FOREIGN_SOLD_TO,
                'ship_to_gln' => self::FOREIGN_SHIP_TO,
            ]);

            $created = app(RecordDestinationGlnMismatch::class)->handle($document);

            $this->assertCount(2, $created);
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE)
                    ->where('status', 'open')
                    ->exists(),
            );
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE)
                    ->where('status', 'open')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function matching_tenant_glns_do_not_raise_exceptions(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureTenantGlnSet($tenant, self::TENANT_GLN);
            $this->setProfile(tenant(), TenantProfile::Pharmacy);

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::TENANT_GLN,
                'ship_to_gln' => self::TENANT_GLN,
            ]);

            $created = app(RecordDestinationGlnMismatch::class)->handle($document);

            $this->assertSame([], $created);
            $this->assertSame(
                0,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->whereIn('exception_type', [
                        RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
                        RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
                    ])
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function three_pl_allows_external_sold_to_but_flags_foreign_ship_to(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureTenantGlnSet($tenant, self::TENANT_GLN);
            $this->setProfile(tenant(), TenantProfile::Logistics3pl);

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::FOREIGN_SOLD_TO,
                'ship_to_gln' => self::FOREIGN_SHIP_TO,
            ]);

            app(RecordDestinationGlnMismatch::class)->handle($document);

            $this->assertFalse(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE)
                    ->exists(),
            );
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE)
                    ->where('status', 'open')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function empty_tenant_gln_set_skips_emission(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);

            $emptySet = new class extends TenantGlnSet
            {
                public function all(): array
                {
                    return [];
                }

                public function isEmpty(): bool
                {
                    return true;
                }

                public function contains(?string $gln): bool
                {
                    return false;
                }
            };

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::FOREIGN_SOLD_TO,
                'ship_to_gln' => self::FOREIGN_SHIP_TO,
            ]);

            $created = new RecordDestinationGlnMismatch(
                app(RecordOperationalEpcisException::class),
                $emptySet,
            )->handle($document);

            $this->assertSame([], $created);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function handle_clears_stale_open_rows_when_glns_now_match(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureTenantGlnSet($tenant, self::TENANT_GLN);
            $this->setProfile(tenant(), TenantProfile::Pharmacy);

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::FOREIGN_SOLD_TO,
                'ship_to_gln' => self::FOREIGN_SHIP_TO,
            ]);

            app(RecordDestinationGlnMismatch::class)->handle($document);
            $this->assertSame(
                2,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->whereIn('exception_type', [
                        RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
                        RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
                    ])
                    ->where('status', 'open')
                    ->count(),
            );

            $document->forceFill([
                'receiver_gln' => self::TENANT_GLN,
                'ship_to_gln' => self::TENANT_GLN,
            ])->save();

            $created = app(RecordDestinationGlnMismatch::class)->handle($document->fresh());
            $this->assertSame([], $created);
            $this->assertSame(
                0,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->whereIn('exception_type', [
                        RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
                        RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
                    ])
                    ->where('status', 'open')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function same_mismatched_gln_emits_only_owning_party_code(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureTenantGlnSet($tenant, self::TENANT_GLN);
            $this->setProfile(tenant(), TenantProfile::Pharmacy);

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::FOREIGN_SOLD_TO,
                'ship_to_gln' => self::FOREIGN_SOLD_TO,
            ]);

            app(RecordDestinationGlnMismatch::class)->handle($document);

            $this->assertSame(
                1,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE)
                    ->where('status', 'open')
                    ->count(),
            );
            $this->assertSame(
                0,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE)
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function setting_off_allows_receive_despite_destination_mismatch(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureTenantGlnSet($tenant, self::TENANT_GLN);
            $this->setProfile(tenant(), TenantProfile::Pharmacy);
            TenantSettings::forTenant(tenant())->setBlockReceiveOnDestinationGlnMismatch(false);

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::FOREIGN_SOLD_TO,
                'ship_to_gln' => self::FOREIGN_SHIP_TO,
            ]);

            app(RecordDestinationGlnMismatch::class)->handle($document);

            $this->assertNull(app(ReceivingGate::class)->documentBlockedAfterDestinationRecheck($document));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function setting_on_blocks_receive_for_destination_mismatch(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureTenantGlnSet($tenant, self::TENANT_GLN);
            $this->setProfile(tenant(), TenantProfile::Pharmacy);
            TenantSettings::forTenant(tenant())->setBlockReceiveOnDestinationGlnMismatch(true);

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::FOREIGN_SOLD_TO,
                'ship_to_gln' => self::FOREIGN_SHIP_TO,
            ]);

            app(RecordDestinationGlnMismatch::class)->handle($document);

            $blocking = app(ReceivingGate::class)->documentBlockedAfterDestinationRecheck($document);
            $this->assertNotNull($blocking);
            $this->assertContains(
                $blocking->type?->code,
                [
                    RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
                    RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
                ],
            );
            $this->assertTrue($blocking->type?->blocksReceiving() ?? false);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function fixing_glns_clears_signals_and_unblocks_receive_when_setting_on(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureTenantGlnSet($tenant, self::TENANT_GLN);
            $this->setProfile(tenant(), TenantProfile::Pharmacy);
            TenantSettings::forTenant(tenant())->setBlockReceiveOnDestinationGlnMismatch(true);

            $document = $this->makeInboundDocument([
                'receiver_gln' => self::FOREIGN_SOLD_TO,
                'ship_to_gln' => self::FOREIGN_SHIP_TO,
            ]);

            app(RecordDestinationGlnMismatch::class)->handle($document);
            $this->assertNotNull(app(ReceivingGate::class)->documentBlockedAfterDestinationRecheck($document));

            $document->forceFill([
                'receiver_gln' => self::TENANT_GLN,
                'ship_to_gln' => self::TENANT_GLN,
            ])->save();

            app(RecordDestinationGlnMismatch::class)->handle($document->fresh());

            $this->assertNull(app(ReceivingGate::class)->documentBlockedAfterDestinationRecheck($document->fresh()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeInboundDocument(array $overrides): EpcisDocument
    {
        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'status' => 'validated',
            'format' => 'xml',
            'original_filename' => 'dest-gln-mismatch-test.xml',
            'dscsa_affirm' => true,
            'creation_date' => now(),
            'received_at' => now(),
        ], $overrides));

        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function ensureTenantGlnSet(Tenant $tenant, string $gln): Tenant
    {
        $this->originalTenantGln = $tenant->gln;
        $this->originalCompanyPrefix = $tenant->company_prefix;
        $digits = preg_replace('/\D+/', '', $gln) ?? '';
        $prefix = strlen($digits) >= 6 ? substr($digits, 0, 6) : null;
        $tenant->forceFill([
            'gln' => $gln,
            'company_prefix' => $prefix,
        ])->save();
        tenancy()->initialize($tenant->fresh());

        if (! (new TenantGlnSet)->contains($gln)) {
            $existingSiteId = Site::query()->where('gln', $gln)->value('id');
            if ($existingSiteId !== null) {
                $this->siteIds[] = (int) $existingSiteId;
            } else {
                $site = Site::factory()->owned()->create([
                    'name' => 'Dest GLN test site',
                    'gln' => $gln,
                    'is_active' => true,
                    // EligibleReceiveSites excludes TEST-* codes from TenantGlnSet site list;
                    // org GLN alone is enough for contains().
                    'code' => 'HQ-DEST-'.fake()->unique()->numerify('###'),
                ]);
                $this->siteIds[] = (int) $site->getKey();
            }
        }

        $this->assertTrue((new TenantGlnSet)->contains($gln));

        return tenant();
    }

    private function setProfile(Tenant $tenant, TenantProfile $profile): Tenant
    {
        $this->originalProfile ??= $tenant->profile;
        $tenant->forceFill(['profile' => $profile])->save();
        tenancy()->initialize($tenant->fresh());

        return tenant();
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

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            foreach ($this->documentIds as $documentId) {
                EpcisException::query()->where('document_id', $documentId)->delete();
                ExceptionCase::query()->where('document_id', $documentId)->delete();
                EpcisDocument::query()->whereKey($documentId)->delete();
            }
            $this->documentIds = [];

            foreach ($this->siteIds as $siteId) {
                Site::query()->whereKey($siteId)->delete();
            }
            $this->siteIds = [];

            TenantSettings::forTenant(tenant())->setBlockReceiveOnDestinationGlnMismatch(false);
            app(SyncDestinationGlnMismatchReceiveImpact::class)->syncTypes(false);

            tenancy()->end();
        }

        if ($this->originalTenantGln !== null || $this->originalCompanyPrefix !== null || $tenant->gln === null) {
            $tenant->forceFill([
                'gln' => $this->originalTenantGln,
                'company_prefix' => $this->originalCompanyPrefix,
            ])->save();
            $this->originalTenantGln = null;
            $this->originalCompanyPrefix = null;
        }

        if ($this->originalProfile !== null) {
            $tenant->forceFill(['profile' => $this->originalProfile])->save();
            $this->originalProfile = null;
        }
    }
}
