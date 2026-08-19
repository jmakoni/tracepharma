<?php

namespace Tests\Unit;

use App\Support\Gs1\Gtin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GtinTest extends TestCase
{
    #[Test]
    public function it_derives_a_gtin_from_a_package_ndc(): void
    {
        // package_ndc 43742-2266-1 -> digits 4374222661 -> body 0034374222661
        // -> GS1 mod-10 check digit 2 -> 00343742226612.
        $this->assertSame('00343742226612', Gtin::fromPackageNdc('43742-2266-1'));
        $this->assertSame('00343742226612', Gtin::fromPackageNdc('4374222661'));
    }

    #[Test]
    public function it_returns_null_for_an_invalid_ndc_length(): void
    {
        $this->assertNull(Gtin::fromPackageNdc('12345'));
        $this->assertNull(Gtin::fromPackageNdc('123456789012'));
        $this->assertNull(Gtin::fromPackageNdc(''));
    }

    #[Test]
    public function it_reads_the_packaging_indicator_digit(): void
    {
        // Same package NDC at two packaging levels: the each, then the case over it.
        $this->assertSame(0, Gtin::packagingIndicator('00343742226612'));
        $this->assertSame(3, Gtin::packagingIndicator('30343742226613'));
    }

    #[Test]
    public function it_reports_no_packaging_level_for_a_variable_measure_or_unreadable_gtin(): void
    {
        // 9 is reserved for variable measure trade items and names no level.
        $this->assertNull(Gtin::packagingIndicator('90343742226619'));
        $this->assertNull(Gtin::packagingIndicator('343742226612'));
        $this->assertNull(Gtin::packagingIndicator(''));
        $this->assertNull(Gtin::packagingIndicator(null));
    }

    #[Test]
    public function it_validates_and_normalizes_a_upc_to_a_gtin_14(): void
    {
        $this->assertSame('00343742226612', Gtin::fromUpc('343742226612'));
        $this->assertSame('00343742226612', Gtin::fromUpc('0343742226612'));

        // Bad check digit should be rejected.
        $this->assertNull(Gtin::fromUpc('343742226610'));
        $this->assertNull(Gtin::fromUpc(null));
        $this->assertNull(Gtin::fromUpc(''));
        $this->assertNull(Gtin::fromUpc('123'));
    }

    #[Test]
    public function from_upc_accepts_only_real_gs1_structure_lengths(): void
    {
        // GTIN-8: body 0361000 -> check digit 8.
        $this->assertSame('00000003610008', Gtin::fromUpc('03610008'));

        // UPC-A (12), EAN-13 and GTIN-14 spellings of the same package.
        $this->assertSame('00343742226612', Gtin::fromUpc('343742226612'));
        $this->assertSame('00343742226612', Gtin::fromUpc('0343742226612'));
        $this->assertSame('00343742226612', Gtin::fromUpc('00343742226612'));

        // 9, 10 and 11 digits are not GS1 structures. Zero-padding an 11-digit value reads
        // its last product digit as the check digit, and '34374222669' passes Mod-10 that
        // way — it would have become 00034374222669, a GTIN for no real package.
        $this->assertNull(Gtin::fromUpc('34374222669'));
        $this->assertNull(Gtin::fromUpc('34374222661'));
        $this->assertNull(Gtin::fromUpc('3437422266'));
        $this->assertNull(Gtin::fromUpc('343742226'));
        $this->assertNull(Gtin::fromUpc('003437422266123'));
    }

    #[Test]
    public function for_packaging_prefers_a_valid_upc_over_the_ndc_derived_gtin(): void
    {
        // Valid UPC wins even though it differs from the NDC-derived GTIN.
        $this->assertSame(
            '00036000291452',
            Gtin::forPackaging('036000291452', '43742-2266-1')
        );

        // Invalid UPC falls back to the NDC-derived GTIN.
        $this->assertSame(
            '00343742226612',
            Gtin::forPackaging('not-a-upc', '43742-2266-1')
        );

        // No UPC at all falls back to the NDC-derived GTIN.
        $this->assertSame(
            '00343742226612',
            Gtin::forPackaging(null, '43742-2266-1')
        );
    }

    #[Test]
    public function it_extracts_ndc10_from_an_ndc_encoded_gtin_14(): void
    {
        $this->assertSame('0116400541', Gtin::ndc10FromNdcEncodedGtin('30301164005414'));

        // Indicator digit 0 form.
        $this->assertSame('0116400541', Gtin::ndc10FromNdcEncodedGtin('00301164005413'));
    }

    #[Test]
    public function it_returns_null_when_reversing_an_invalid_ndc_encoded_gtin(): void
    {
        // Bad check digit.
        $this->assertNull(Gtin::ndc10FromNdcEncodedGtin('30301164005410'));

        // Body positions 1-2 are not "03" (not NDC-encoded); check digit is valid.
        $this->assertNull(Gtin::ndc10FromNdcEncodedGtin('00036000291452'));

        // Wrong length.
        $this->assertNull(Gtin::ndc10FromNdcEncodedGtin('303011640054'));
        $this->assertNull(Gtin::ndc10FromNdcEncodedGtin('3030116400541411'));

        $this->assertNull(Gtin::ndc10FromNdcEncodedGtin(null));
        $this->assertNull(Gtin::ndc10FromNdcEncodedGtin(''));
    }
}
