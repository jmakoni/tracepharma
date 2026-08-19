<?php

namespace Tests\Feature;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Filament\App\Support\FdaPicker;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\TradingPartner;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\FdaPrefill;
use App\Support\MasterData\PartnerSiteCreate;
use Filament\Forms\Components\Select;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FdaPrefillTest extends TestCase
{
    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $packagingIds = [];

    protected function tearDown(): void
    {
        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
        }

        if ($this->productIds !== []) {
            FdaProduct::query()->whereIn('id', $this->productIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaEstablishment::query()->whereIn('fda_organization_id', $this->orgIds)->delete();
            FdaWddFacility::query()->whereIn('fda_organization_id', $this->orgIds)->delete();
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function organization_attributes_map_address_fields(): void
    {
        $org = FdaOrganization::unguarded(fn () => new FdaOrganization([
            'id' => 42,
            'name' => 'SSOR PREFILL Org',
            'partner_type' => PartnerType::Manufacturer,
            'state_province' => 'TX',
            'postal_code' => '78701',
            'is_active' => true,
        ]));

        $attrs = FdaPrefill::organizationAttributes($org);

        $this->assertSame(42, $attrs['fda_organization_id']);
        $this->assertSame('TX', $attrs['state']);
        $this->assertSame('78701', $attrs['zipcode']);
        $this->assertSame(PartnerType::Manufacturer->value, $attrs['partner_type']);
        $this->assertArrayNotHasKey('id', $attrs);
    }

    #[Test]
    public function packaging_attributes_include_listing_fields_and_allow_otc(): void
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR PREFILL Labeler',
            'canonical_name' => 'SSOR PREFILL LABELER',
            'name' => 'SSOR PREFILL Labeler',
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        $listing = FdaProduct::query()->create([
            'product_id' => 'SSOR-PREFILL-OTC',
            'product_ndc' => '88884-201',
            'name' => 'SSOR Prefill OTC',
            'brand_name' => 'SSOR Prefill OTC',
            'dosage_form' => 'TABLET',
            'strength' => '10 mg',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_OTC,
            'fda_organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->productIds[] = $listing->id;

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => '88884-201-01',
            'gtin' => '88884000000013',
            'ndc11' => '88884020101',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;
        $packaging->setRelation('product', $listing);

        $attrs = FdaPrefill::packagingAttributes($packaging);

        $this->assertSame($packaging->id, $attrs['fda_product_packaging_id']);
        $this->assertSame($listing->id, $attrs['fda_product_id']);
        $this->assertSame('88884000000013', $attrs['gtin']);
        $this->assertSame('SSOR Prefill OTC', $attrs['name']);
        $this->assertSame('TABLET', $attrs['dosage_form']);
        $this->assertSame('10 mg', $attrs['strength']);
        $this->assertSame('88884-201-01', $attrs['package_ndc']);
    }

    #[Test]
    public function manufacturer_can_pick_a_same_org_wdd_facility(): void
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR PREFILL Mfr',
            'canonical_name' => 'SSOR PREFILL MFR',
            'name' => 'SSOR PREFILL Mfr',
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR PREFILL DC',
            'facility_name' => 'SSOR PREFILL DC',
            'address_fingerprint' => AddressFingerprint::make('9 Prefill St', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);

        $partner = new TradingPartner([
            'fda_organization_id' => $org->id,
            'partner_type' => PartnerType::Manufacturer,
        ]);

        $this->assertNull(PartnerSiteCreate::wddFacilityProblemFor($partner, $facility));
    }

    #[Test]
    public function packaging_picker_finds_otc_packages_by_ndc_and_gtin(): void
    {
        $listing = FdaProduct::query()->create([
            'product_id' => 'SSOR-PREFILL-PICK',
            'product_ndc' => '88884-301',
            'name' => 'SSOR Prefill Pick',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_OTC,
            'is_active' => true,
        ]);
        $this->productIds[] = $listing->id;

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => '88884-301-01',
            'gtin' => '88884000000020',
            'ndc11' => '88884030101',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        $select = collect(FdaPicker::packaging())
            ->first(fn ($component) => $component instanceof Select);

        $this->assertInstanceOf(Select::class, $select);
        $this->assertTrue($select->isSearchable());
        $this->assertSame(500, $select->getSearchDebounce());

        foreach ([$packaging->package_ndc, $packaging->ndc11, $packaging->gtin] as $term) {
            $this->assertArrayHasKey(
                $packaging->getKey(),
                $select->getSearchResults((string) $term),
                "The picker did not find the package by [{$term}].",
            );
        }
    }

    #[Test]
    public function merge_keeps_a_typed_gln_when_the_registry_row_has_none(): void
    {
        $merged = FdaPrefill::mergeKeepingFilledGln(
            ['name' => 'Typed', 'gln' => '0301160000016'],
            ['name' => 'From FDA', 'gln' => null, 'city' => 'Glenview'],
        );

        $this->assertSame('From FDA', $merged['name']);
        $this->assertSame('Glenview', $merged['city']);
        $this->assertSame('0301160000016', $merged['gln']);
    }

    #[Test]
    public function merge_lets_a_registry_gln_replace_the_typed_value(): void
    {
        $merged = FdaPrefill::mergeKeepingFilledGln(
            ['gln' => '0301160000016'],
            ['gln' => '0301160000009'],
        );

        $this->assertSame('0301160000009', $merged['gln']);
    }
}
