<?php

namespace Tests\Unit\Services\Dscsa;

use App\Actions\Epcis\ResolveGlnToMasterData;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Services\Dscsa\Support\DscsaDirectPurchaseStatements;
use App\Services\Dscsa\Support\EpcisShipmentReportContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DscsaDirectPurchaseStatementsTest extends TestCase
{
    private function statements(): DscsaDirectPurchaseStatements
    {
        return new DscsaDirectPurchaseStatements(app(ResolveGlnToMasterData::class));
    }

    #[Test]
    public function manufacturer_template_uses_manufactured_by_wording(): void
    {
        $statement = $this->statements()->statementForSeller(PartnerType::Manufacturer, 'Xttrium Laboratories, Inc.');

        $this->assertStringContainsString('manufactured by Xttrium Laboratories, Inc.', (string) $statement);
        $this->assertStringNotContainsString('purchased directly from the manufacturer', (string) $statement);
    }

    #[Test]
    public function wholesaler_template_uses_gs1_direct_purchase_wording(): void
    {
        $statement = $this->statements()->statementForSeller(PartnerType::Wholesaler, 'Cardinal Health');

        $this->assertStringContainsString('purchased directly from the manufacturer', (string) $statement);
    }

    #[Test]
    public function three_pl_seller_omits_generated_statement(): void
    {
        $this->assertNull($this->statements()->statementForSeller(PartnerType::Logistics3pl, '3PL Co'));
    }

    #[Test]
    public function persisted_inbound_statement_wins_over_generated_template(): void
    {
        $context = app(EpcisShipmentReportContext::class);

        $document = new EpcisDocument([
            'dscsa_affirm' => true,
            'direct_purchase_statement' => 'Inbound authored direct purchase statement.',
        ]);

        $statement = $context->directPurchaseStatement($document, 'Any Seller', '0301160000009');

        $this->assertSame('Inbound authored direct purchase statement.', $statement);
    }

    #[Test]
    public function manufacturer_tenant_profile_maps_to_manufacturer_partner_type(): void
    {
        $tenant = new Tenant(['profile' => TenantProfile::Manufacturer]);

        $this->assertSame(PartnerType::Manufacturer, $this->statements()->tenantProfileToPartnerType($tenant));
    }
}
