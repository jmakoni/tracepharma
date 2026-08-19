<?php

namespace Tests\Feature;

use App\Actions\Places\BackfillCatalogPartnerPlaces;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Support\Fda\AddressFingerprint;
use App\Support\Places\FixturePlacesClient;
use App\Support\Places\PlacesClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillCatalogPartnerPlacesTest extends TestCase
{
    /** Distinct from the real Amneal FDA org so cleanup cannot wipe production data. */
    private const ORG_NAME = 'Amneal Pharmaceuticals LLC Places Fixture';

    /** @var list<int> */
    private array $createdOrganizationIds = [];

    /** @var list<int> */
    private array $createdFacilityIds = [];

    /** @var list<int> */
    private array $createdEstablishmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupFixtureState();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtureState();
        parent::tearDown();
    }

    private function cleanupFixtureState(): void
    {
        if ($this->createdFacilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->createdFacilityIds)->delete();
        }

        if ($this->createdEstablishmentIds !== []) {
            FdaEstablishment::query()->whereIn('id', $this->createdEstablishmentIds)->delete();
        }

        $ids = array_values(array_unique(array_merge(
            $this->createdOrganizationIds,
            FdaOrganization::query()->where('name', self::ORG_NAME)->pluck('id')->all(),
            FdaOrganization::query()->where('name', 'like', self::ORG_NAME.'%')->pluck('id')->all(),
        )));

        if ($ids !== []) {
            FdaWddFacility::query()->whereIn('fda_organization_id', $ids)->delete();
            FdaEstablishment::query()->whereIn('fda_organization_id', $ids)->delete();
            FdaOrganization::query()->whereIn('id', $ids)->delete();
        }

        $this->createdOrganizationIds = [];
        $this->createdFacilityIds = [];
        $this->createdEstablishmentIds = [];
    }

    private function bindAmnealFixture(): void
    {
        $this->app->instance(
            PlacesClient::class,
            new FixturePlacesClient(base_path('tests/fixtures/places/amneal.json'))
        );
    }

    #[Test]
    public function backfills_organization_blanks_without_creating_fda_sites(): void
    {
        $this->bindAmnealFixture();

        $organization = $this->createBlankOrganization(self::ORG_NAME);
        $facilitiesBefore = FdaWddFacility::query()->count();
        $establishmentsBefore = FdaEstablishment::query()->count();

        $result = app(BackfillCatalogPartnerPlaces::class)->handle($organization, onlyMissing: true, dryRun: false);

        $this->assertSame(0, $result['skipped_has_address']);
        $this->assertSame(0, $result['no_results']);
        $this->assertSame(1, $result['hq_filled']);
        $this->assertSame(0, $result['sites_upserted']);
        $this->assertSame(1, $result['rejected']);
        $this->assertFalse($result['dry_run']);

        $organization->refresh();
        $this->assertSame('400 Crossing Blvd', $organization->street_address);
        $this->assertSame('Bridgewater', $organization->city);
        $this->assertSame('NJ', $organization->state_province);
        $this->assertSame('08807', $organization->postal_code);
        $this->assertNotNull($organization->latitude);
        $this->assertEqualsWithDelta(40.5836335, (float) $organization->latitude, 0.001);
        $this->assertSame('https://amneal.com', $organization->website);
        $this->assertSame('America/New_York', $organization->timezone);

        $this->assertSame($facilitiesBefore, FdaWddFacility::query()->count());
        $this->assertSame($establishmentsBefore, FdaEstablishment::query()->count());
    }

    #[Test]
    public function fills_matching_existing_establishment_blanks_only(): void
    {
        $this->bindAmnealFixture();

        $organization = $this->createBlankOrganization(self::ORG_NAME);
        $fingerprint = AddressFingerprint::make('400 Crossing Blvd', 'Bridgewater', 'NJ', '08807', 'US');

        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $organization->id,
            'firm_name' => 'SSOR Places HQ Est',
            'name' => null,
            'address_fingerprint' => $fingerprint,
            'is_active' => true,
        ]);
        $this->createdEstablishmentIds[] = $establishment->id;

        $facilitiesBefore = FdaWddFacility::query()->count();

        $result = app(BackfillCatalogPartnerPlaces::class)->handle($organization, onlyMissing: true, dryRun: false);

        $this->assertSame(1, $result['hq_filled']);
        $this->assertSame(1, $result['sites_upserted']);
        $this->assertSame($facilitiesBefore, FdaWddFacility::query()->count());

        $establishment->refresh();
        $this->assertSame('Amneal Pharmaceuticals', $establishment->name);
        $this->assertSame('400 Crossing Blvd', $establishment->street_address);
        $this->assertSame('Bridgewater', $establishment->city);
        $this->assertSame('NJ', $establishment->state_province);
        $this->assertSame('08807', $establishment->postal_code);
        $this->assertSame('America/New_York', $establishment->timezone);
    }

    #[Test]
    public function skips_organizations_that_already_have_an_address_when_only_missing(): void
    {
        $this->bindAmnealFixture();

        $organization = FdaOrganization::query()->create([
            'original_name' => self::ORG_NAME,
            'canonical_name' => strtoupper(self::ORG_NAME),
            'name' => self::ORG_NAME,
            'partner_type' => PartnerType::Manufacturer,
            'street_address' => '1 Existing Way',
            'is_active' => true,
        ]);
        $this->createdOrganizationIds[] = $organization->id;

        $result = app(BackfillCatalogPartnerPlaces::class)->handle($organization, onlyMissing: true, dryRun: false);

        $this->assertSame(1, $result['skipped_has_address']);
        $this->assertSame(0, $result['hq_filled']);
        $this->assertSame('1 Existing Way', $organization->fresh()->street_address);
        $this->assertSame(0, FdaWddFacility::query()->where('fda_organization_id', $organization->id)->count());
        $this->assertSame(0, FdaEstablishment::query()->where('fda_organization_id', $organization->id)->count());
    }

    #[Test]
    public function dry_run_computes_result_without_writing_to_the_database(): void
    {
        $this->bindAmnealFixture();

        $organization = $this->createBlankOrganization(self::ORG_NAME);

        $result = app(BackfillCatalogPartnerPlaces::class)->handle($organization, onlyMissing: true, dryRun: true);

        $this->assertSame(1, $result['hq_filled']);
        $this->assertSame(0, $result['sites_upserted']);
        $this->assertTrue($result['dry_run']);

        $this->assertNull($organization->fresh()->street_address);
        $this->assertSame(0, FdaWddFacility::query()->where('fda_organization_id', $organization->id)->count());
    }

    #[Test]
    public function fills_hq_when_alternate_query_returns_places_after_original_misses(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/places/amneal.json')),
            true
        )['data'];

        $originalName = 'Amneal Pharmaceuticals LLC Places Expand';

        $this->app->instance(PlacesClient::class, new class($originalName, $fixture) implements PlacesClient
        {
            /**
             * @param  list<array<string, mixed>>  $fixture
             */
            public function __construct(
                private readonly string $failQuery,
                private readonly array $fixture,
            ) {}

            public function search(string $query): array
            {
                if (strcasecmp($query, $this->failQuery) === 0) {
                    return [];
                }

                return $this->fixture;
            }
        });

        $organization = $this->createBlankOrganization($originalName);

        $result = app(BackfillCatalogPartnerPlaces::class)->handle($organization, onlyMissing: true, dryRun: false);

        $this->assertGreaterThan(1, $result['queries_tried']);
        $this->assertSame(1, $result['hq_filled']);
        $this->assertSame(0, $result['no_results']);

        $organization->refresh();
        $this->assertSame('400 Crossing Blvd', $organization->street_address);
        $this->assertSame('Bridgewater', $organization->city);
    }

    #[Test]
    public function rerunning_the_backfill_is_idempotent(): void
    {
        $this->bindAmnealFixture();

        $organization = $this->createBlankOrganization(self::ORG_NAME);
        $fingerprint = AddressFingerprint::make('400 Crossing Blvd', 'Bridgewater', 'NJ', '08807', 'US');
        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $organization->id,
            'firm_name' => 'SSOR Places HQ Est Idem',
            'address_fingerprint' => $fingerprint,
            'is_active' => true,
        ]);
        $this->createdEstablishmentIds[] = $establishment->id;

        $action = app(BackfillCatalogPartnerPlaces::class);
        $action->handle($organization, onlyMissing: true, dryRun: false);

        $sitesAfterFirst = FdaEstablishment::query()->where('fda_organization_id', $organization->id)->count()
            + FdaWddFacility::query()->where('fda_organization_id', $organization->id)->count();

        $second = $action->handle($organization->fresh(), onlyMissing: true, dryRun: false);
        $this->assertSame(1, $second['skipped_has_address']);
        $this->assertSame(
            $sitesAfterFirst,
            FdaEstablishment::query()->where('fda_organization_id', $organization->id)->count()
            + FdaWddFacility::query()->where('fda_organization_id', $organization->id)->count()
        );

        $third = $action->handle($organization->fresh(), onlyMissing: false, dryRun: false);
        $this->assertSame(1, $third['hq_filled']);
        $this->assertSame(1, $third['sites_upserted']);
        $this->assertSame(
            $sitesAfterFirst,
            FdaEstablishment::query()->where('fda_organization_id', $organization->id)->count()
            + FdaWddFacility::query()->where('fda_organization_id', $organization->id)->count()
        );
    }

    #[Test]
    public function matching_wdd_facility_blanks_are_filled_without_creating_another_row(): void
    {
        $this->bindAmnealFixture();

        $organization = $this->createBlankOrganization(self::ORG_NAME);
        $fingerprint = AddressFingerprint::make('400 Crossing Blvd', 'Bridgewater', 'NJ', '08807', 'US');

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $organization->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => 'SSOR Places WDD',
            'name' => null,
            'address_fingerprint' => $fingerprint,
            'is_active' => true,
        ]);
        $this->createdFacilityIds[] = $facility->id;

        $countBefore = FdaWddFacility::query()->where('fda_organization_id', $organization->id)->count();

        $result = app(BackfillCatalogPartnerPlaces::class)->handle($organization, onlyMissing: true, dryRun: false);

        $this->assertSame(1, $result['sites_upserted']);
        $this->assertSame(
            $countBefore,
            FdaWddFacility::query()->where('fda_organization_id', $organization->id)->count()
        );

        $facility->refresh();
        $this->assertSame('Amneal Pharmaceuticals', $facility->name);
        $this->assertSame('Bridgewater', $facility->city);
        $this->assertSame('NJ', $facility->state_province);
    }

    private function createBlankOrganization(string $name): FdaOrganization
    {
        $organization = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => strtoupper($name),
            'name' => $name,
            'partner_type' => PartnerType::Manufacturer,
            'street_address' => null,
            'city' => null,
            'state_province' => null,
            'postal_code' => null,
            'latitude' => null,
            'longitude' => null,
            'is_active' => true,
        ]);
        $this->createdOrganizationIds[] = $organization->id;

        return $organization;
    }
}
