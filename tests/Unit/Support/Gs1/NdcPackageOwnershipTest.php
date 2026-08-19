<?php

namespace Tests\Unit\Support\Gs1;

use App\Support\Gs1\Ndc;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NdcPackageOwnershipTest extends TestCase
{
    #[Test]
    public function product_ndc_normalizes_to_nine_digits_for_every_segment_spelling(): void
    {
        $this->assertSame('001164005', Ndc::toProductNdc9('0116-4005'));
        $this->assertSame('999990303', Ndc::toProductNdc9('99999-303'));
        $this->assertSame('123456789', Ndc::toProductNdc9('12345-6789'));
        $this->assertNull(Ndc::toProductNdc9('0116-4005-08'));
        $this->assertNull(Ndc::toProductNdc9(''));
        $this->assertNull(Ndc::toProductNdc9(null));
    }

    #[Test]
    public function package_ownership_matches_the_product_that_claims_it(): void
    {
        $this->assertTrue(Ndc::productOwnsPackage('0116-4005', '0116-4005-08'));
        $this->assertTrue(Ndc::productOwnsPackage('99999-303', '99999-303-01'));

        // Same package, spelled 5-4-2 against a 4-4 product NDC.
        $this->assertTrue(Ndc::productOwnsPackage('0116-4005', '00116-4005-08'));
    }

    #[Test]
    public function a_package_from_another_product_is_not_owned(): void
    {
        $this->assertFalse(Ndc::productOwnsPackage('0116-4005', '0116-4006-08'));
        $this->assertFalse(Ndc::productOwnsPackage('99999-303', '0116-4005-08'));
        $this->assertFalse(Ndc::productOwnsPackage('0116-4005', ''));
        $this->assertFalse(Ndc::productOwnsPackage(null, '0116-4005-08'));
    }
}
