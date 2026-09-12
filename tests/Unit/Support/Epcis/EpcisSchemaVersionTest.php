<?php

namespace Tests\Unit\Support\Epcis;

use App\Models\Tenant;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\TenantSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisSchemaVersionTest extends TestCase
{
    #[Test]
    public function peek_accepts_1_2_and_1_3_by_default(): void
    {
        config(['tracepharma.epcis.accept_20' => false]);

        $this->assertSame('1.2', EpcisSchemaVersion::peek('schemaVersion="1.2"'));
        $this->assertSame('1.3', EpcisSchemaVersion::peek("schemaVersion='1.3'"));
        $this->assertSame('2.0', EpcisSchemaVersion::peek('schemaVersion="2.0"'));
        $this->assertTrue(EpcisSchemaVersion::isAccepted('1.2'));
        $this->assertTrue(EpcisSchemaVersion::isAccepted('1.3'));
        $this->assertNull(EpcisSchemaVersion::peek('schemaVersion="1.0"'));
        $this->assertFalse(EpcisSchemaVersion::isAccepted('1.0'));
        $this->assertFalse(EpcisSchemaVersion::isAccepted('2.0'));
    }

    #[Test]
    public function accept_20_flag_allows_version_2_0(): void
    {
        config(['tracepharma.epcis.accept_20' => true]);

        $this->assertTrue(EpcisSchemaVersion::isAccepted('2.0'));
        $this->assertContains(EpcisSchemaVersion::V20, EpcisSchemaVersion::accepted());
    }

    #[Test]
    public function peek_file_reads_the_1_3_fixture(): void
    {
        $path = base_path('tests/Fixtures/epcis/minimal_object_shipping_1.3.xml');

        $this->assertSame('1.3', EpcisSchemaVersion::peekFile($path));
    }

    #[Test]
    public function peek_file_reads_the_2_0_json_fixture(): void
    {
        $path = base_path('tests/Fixtures/epcis/minimal_object_packing_2.0.json');

        $this->assertSame('2.0', EpcisSchemaVersion::peekFile($path));
        $this->assertSame(EpcisSchemaVersion::FORMAT_JSON, EpcisSchemaVersion::detectFormat($path));
    }

    #[Test]
    public function assert_accepted_rejects_2_0_when_flag_off(): void
    {
        config(['tracepharma.epcis.accept_20' => false]);

        $this->expectException(\InvalidArgumentException::class);
        EpcisSchemaVersion::assertAccepted('2.0', EpcisSchemaVersion::FORMAT_JSON);
    }

    #[Test]
    public function tenant_can_opt_out_of_accept_20_when_platform_flag_on(): void
    {
        config(['tracepharma.epcis.accept_20' => true]);

        $tenant = Tenant::query()->find('13fe9068-cb05-4bab-9e0e-a89f2a458832');
        if ($tenant === null) {
            $this->markTestSkipped('Demo2 tenant not present.');
        }

        tenancy()->initialize($tenant);
        try {
            TenantSettings::forTenant($tenant)->setEpcisAccept20(false);
            $tenant->save();

            $this->assertTrue(EpcisSchemaVersion::accepts20PlatformOnly());
            $this->assertFalse(EpcisSchemaVersion::accepts20($tenant));
            $this->assertFalse(EpcisSchemaVersion::isAccepted('2.0'));
        } finally {
            TenantSettings::forTenant($tenant)->setEpcisAccept20(null);
            $tenant->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function content_type_for_format_maps_json_and_xml(): void
    {
        $this->assertSame('application/ld+json', EpcisSchemaVersion::contentTypeForFormat(EpcisSchemaVersion::FORMAT_JSON));
        $this->assertSame('application/xml', EpcisSchemaVersion::contentTypeForFormat(EpcisSchemaVersion::FORMAT_XML));
    }
}
