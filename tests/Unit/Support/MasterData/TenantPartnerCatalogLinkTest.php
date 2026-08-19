<?php

namespace Tests\Unit\Support\MasterData;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\TradingPartner;
use App\Support\MasterData\TenantPartnerCatalogLink;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantPartnerCatalogLinkTest extends TestCase
{
    #[Test]
    public function a_curated_partner_name_survives_the_fda_link(): void
    {
        $partner = new TradingPartner([
            'name' => 'Midwest Rx Distributors (Dock 4 contact)',
            'partner_type' => PartnerType::Wholesaler,
        ]);

        $attributes = TenantPartnerCatalogLink::attributesFor(
            $partner,
            $this->organization('MIDWEST RX DISTRIBUTORS INC.'),
            PartnerType::Wholesaler,
        );

        $this->assertArrayNotHasKey(
            'name',
            $attributes,
            'Ingest enriches; it does not rename what the tenant already curated.',
        );
    }

    #[Test]
    public function a_blank_partner_name_is_filled_from_the_fda_organization(): void
    {
        $attributes = TenantPartnerCatalogLink::attributesFor(
            new TradingPartner(['name' => '   ']),
            $this->organization('Midwest Rx Distributors Inc.'),
            PartnerType::Wholesaler,
        );

        $this->assertSame('Midwest Rx Distributors Inc.', $attributes['name'] ?? null);
    }

    #[Test]
    public function a_blank_organization_name_leaves_the_partner_untouched(): void
    {
        $attributes = TenantPartnerCatalogLink::attributesFor(
            new TradingPartner(['name' => '']),
            $this->organization(''),
            PartnerType::Wholesaler,
        );

        $this->assertArrayNotHasKey('name', $attributes);
    }

    #[Test]
    public function the_fda_link_and_partner_type_are_only_filled_when_still_open(): void
    {
        $unclassified = new TradingPartner([
            'name' => 'Unknown Party',
            'partner_type' => PartnerType::Other,
        ]);

        $attributes = TenantPartnerCatalogLink::attributesFor(
            $unclassified,
            $this->organization('Midwest Rx'),
            PartnerType::Manufacturer,
        );

        $this->assertSame(7, $attributes['fda_organization_id'] ?? null);
        $this->assertSame(PartnerType::Manufacturer, $attributes['partner_type'] ?? null);

        $classified = new TradingPartner([
            'name' => 'Midwest Rx',
            'partner_type' => PartnerType::Wholesaler,
            'fda_organization_id' => 3,
        ]);

        $attributes = TenantPartnerCatalogLink::attributesFor(
            $classified,
            $this->organization('Midwest Rx'),
            PartnerType::Manufacturer,
        );

        $this->assertSame([], $attributes, 'Nothing left to enrich means nothing to write.');
    }

    private function organization(string $name): FdaOrganization
    {
        $organization = new FdaOrganization(['name' => $name]);
        $organization->forceFill(['id' => 7]);

        return $organization;
    }
}
