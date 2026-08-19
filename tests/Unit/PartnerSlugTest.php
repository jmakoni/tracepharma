<?php

namespace Tests\Unit;

use App\Support\PartnerSlug;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PartnerSlugTest extends TestCase
{
    #[Test]
    public function xttrium_labeler_variants_share_one_slug(): void
    {
        $expected = 'xttrium-laboratories-inc';

        $this->assertSame($expected, PartnerSlug::from('Xttrium Laboratories, Inc.'));
        $this->assertSame($expected, PartnerSlug::from('Xttrium Laboratories, Inc'));
        $this->assertSame($expected, PartnerSlug::from('Xttrium Laboratories Inc.'));
    }

    #[Test]
    public function distinct_brands_stay_distinct(): void
    {
        $this->assertNotSame(
            PartnerSlug::from('Hikma Pharmaceuticals USA Inc.'),
            PartnerSlug::from('Pfizer Inc.')
        );
    }

    #[Test]
    public function amerisourcebergen_corp_variants_share_one_slug(): void
    {
        $expected = 'amerisourcebergen-drug-corp';

        $this->assertSame($expected, PartnerSlug::from('AmerisourceBergen Drug Corp'));
        $this->assertSame($expected, PartnerSlug::from('AmerisourceBergen Drug Corporation'));
        $this->assertSame($expected, PartnerSlug::from('AmerisourceBergen Drug Corp.'));
    }

    #[Test]
    public function trailing_limited_and_ltd_share_one_slug(): void
    {
        $expected = 'foo-ltd';

        $this->assertSame($expected, PartnerSlug::from('Foo Limited'));
        $this->assertSame($expected, PartnerSlug::from('Foo Ltd'));
        $this->assertSame($expected, PartnerSlug::from('Foo Ltd.'));
    }

    #[Test]
    public function mid_name_limited_is_not_treated_as_trailing_suffix(): void
    {
        $this->assertSame('limited-brands-inc', PartnerSlug::from('Limited Brands Inc'));
    }

    #[Test]
    public function empty_or_non_latin_falls_back_to_hash_slug(): void
    {
        $slug = PartnerSlug::from('日本語');

        $this->assertStringStartsWith('partner-', $slug);
        $this->assertSame(16, strlen($slug));
    }
}
