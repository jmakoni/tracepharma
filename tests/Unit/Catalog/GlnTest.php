<?php

namespace Tests\Unit\Catalog;

use App\Support\Catalog\Gln;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GlnTest extends TestCase
{
    #[Test]
    public function normalizes_short_and_full_glns_to_thirteen_digits(): void
    {
        $this->assertSame('0010939000002', Gln::normalize('10939000002'));
        $this->assertSame('0010939110008', Gln::normalize('0010939110008'));
        $this->assertSame('0010939110008', Gln::normalize('10939110008'));
    }

    #[Test]
    public function rejects_unusable_gln_values(): void
    {
        $this->assertNull(Gln::normalize(null));
        $this->assertNull(Gln::normalize(''));
        $this->assertNull(Gln::normalize('12345'));
    }

    #[Test]
    public function normalizes_short_postal_codes(): void
    {
        $this->assertSame('08691', Gln::normalizePostalCode('8691'));
        $this->assertSame('75039', Gln::normalizePostalCode('75039'));
        $this->assertSame('018441596', Gln::normalizePostalCode('018441596'));
    }

    #[Test]
    public function extracts_location_code_from_sgln_urn(): void
    {
        $this->assertSame(
            '10600',
            Gln::locationCodeFromSgln('urn:epc:id:sgln:0010939.10600.0')
        );
        $this->assertSame(
            '00000',
            Gln::locationCodeFromSgln('urn:epc:id:sgln:0010939.00000.0')
        );
        $this->assertNull(Gln::locationCodeFromSgln('not-an-sgln'));
    }
}
