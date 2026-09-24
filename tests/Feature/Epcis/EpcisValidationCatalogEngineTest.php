<?php

namespace Tests\Feature\Epcis;

use App\Enums\EpcisGuideline;
use App\Models\Epcis\EpcisDocument;
use App\Support\Epcis\DetectDscsaGuidelineRelease;
use App\Support\Epcis\Validation\EpcisValidationCatalog;
use App\Support\Epcis\Validation\EpcisValidationProfile;
use App\Support\Epcis\Validation\EpcisValidationProfileResolver;
use App\Support\Epcis\Validation\EpcisValidationSeverityMap;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisValidationCatalogEngineTest extends TestCase
{
    #[Test]
    public function catalog_lists_all_seeded_codes_and_clearable_subset(): void
    {
        $this->assertCount(75, EpcisValidationCatalog::all());
        $this->assertTrue(EpcisValidationCatalog::isOwned('MISSING_SOURCE_DESTINATION'));
        $this->assertTrue(EpcisValidationCatalog::isOwned('PARTNER_REJECTED_FILE'));
        $this->assertNotContains('PARTNER_REJECTED_FILE', EpcisValidationCatalog::clearableCodes());
        $this->assertNotContains('MASTER_DATA_SYNC_LAG', EpcisValidationCatalog::clearableCodes());
        $this->assertContains('MISSING_DSCSA_STATEMENT', EpcisValidationCatalog::clearableCodes());

        // SHIP_BEFORE_COMMISSION must stay clearable/rewritable on every validation pass even
        // though EpcisCatalogBusinessRules::checkShipmentCommissioning() currently auto-emits it
        // alongside SERIAL_SHIPPED_NOT_COMMISSIONED (and MISSING_COMMISSIONING) as a group,
        // rather than as an independently-triggered finding.
        $this->assertContains('SHIP_BEFORE_COMMISSION', EpcisValidationCatalog::clearableCodes());
    }

    #[Test]
    public function severity_map_treats_invalid_bizstep_as_a_warning(): void
    {
        config(['tracepharma.epcis.validation.default_profile' => 'gs1us_r12']);
        config(['tracepharma.epcis.validation.force_r13' => false]);
        config(['tracepharma.epcis.validation.severity_overrides' => []]);

        $document = new EpcisDocument(['direction' => 'inbound']);
        $ctx = app(EpcisValidationProfileResolver::class)->resolve($document, 'inbound');

        // INVALID_BIZSTEP is a soft/advisory finding (non-CBV bizStep) distinct from hard
        // structural errors like MISSING_MANDATORY_FIELD, so the severity map keeps it at
        // 'warning' rather than escalating it to 'error'.
        $this->assertSame('warning', EpcisValidationSeverityMap::severityFor('INVALID_BIZSTEP', $ctx));
        $this->assertSame('critical', EpcisValidationSeverityMap::severityFor('MISSING_COMMISSIONING', $ctx));
    }

    #[Test]
    public function profile_resolver_defaults_to_r12(): void
    {
        config(['tracepharma.epcis.validation.default_profile' => 'gs1us_r12']);
        config(['tracepharma.epcis.validation.force_r13' => false]);

        $document = new EpcisDocument(['direction' => 'inbound']);
        $ctx = app(EpcisValidationProfileResolver::class)->resolve($document, 'inbound');

        $this->assertSame(EpcisValidationProfile::Gs1UsR12, $ctx->profile);
        $this->assertFalse($ctx->r13Hard);
    }

    #[Test]
    public function force_r13_config_does_not_override_per_document_profile(): void
    {
        config(['tracepharma.epcis.validation.default_profile' => 'gs1us_r12']);
        config(['tracepharma.epcis.validation.force_r13' => true]);

        $document = new EpcisDocument(['direction' => 'inbound']);
        $ctx = app(EpcisValidationProfileResolver::class)->resolve($document, 'inbound');

        $this->assertFalse($ctx->r13Hard);
        $this->assertSame(EpcisValidationProfile::Gs1UsR12, $ctx->profile);
    }

    #[Test]
    public function stored_r13_release_escalates_profile_without_tenant_force(): void
    {
        config([
            'tracepharma.epcis.validation.default_profile' => 'gs1us_r12',
            'tracepharma.epcis.validation.force_r13' => false,
        ]);

        $document = new EpcisDocument([
            'direction' => 'inbound',
            'dscsa_guideline_release' => EpcisGuideline::R13,
        ]);
        $ctx = app(EpcisValidationProfileResolver::class)->resolve($document, 'inbound');

        $this->assertTrue($ctx->r13Hard);
        $this->assertSame(EpcisValidationProfile::Gs1UsR13, $ctx->profile);
    }

    #[Test]
    public function us_fda_ndc_payload_escalates_r13_hard_without_guideline_version(): void
    {
        config([
            'tracepharma.epcis.validation.default_profile' => 'gs1us_r12',
            'tracepharma.epcis.validation.force_r13' => false,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'epcis_r13_');
        $this->assertNotFalse($path);
        file_put_contents(
            $path,
            '<attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">US_FDA_NDC</attribute>',
        );

        try {
            $document = new EpcisDocument(['direction' => 'inbound']);
            $ctx = app(EpcisValidationProfileResolver::class)->resolve($document, 'inbound', $path);

            $this->assertTrue($ctx->r13Hard);
            $this->assertSame(EpcisValidationProfile::Gs1UsR13, $ctx->profile);
            $this->assertSame(
                EpcisGuideline::R13,
                DetectDscsaGuidelineRelease::fromPath($path)->release,
            );
        } finally {
            @unlink($path);
        }
    }
}
