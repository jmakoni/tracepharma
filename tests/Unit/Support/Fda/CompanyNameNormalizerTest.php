<?php

namespace Tests\Unit\Support\Fda;

use App\Support\Fda\CompanyNameNormalizer;
use App\Support\PartnerSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CompanyNameNormalizerTest extends TestCase
{
    #[Test]
    #[DataProvider('xttriumVariants')]
    public function xttrium_variants_share_one_canonical_name(string $name): void
    {
        $this->assertSame('XTTRIUM LABORATORIES', CompanyNameNormalizer::canonical($name));
    }

    /**
     * @return list<list<string>>
     */
    public static function xttriumVariants(): array
    {
        return [
            ['Xttrium Laboratories, Inc.'],
            ['Xttrium Laboratories, Inc'],
            ['Xttrium Laboratories Inc.'],
            ['Xttrium Laboratories Inc;'],
            ['Xttrium Laboratories Corporation'],
            ['THE Xttrium Laboratories, Inc.'],
        ];
    }

    #[Test]
    public function dba_and_fka_keep_the_legal_entity(): void
    {
        $this->assertSame(
            'ACME PHARMA',
            CompanyNameNormalizer::canonical('Acme Pharma Inc dba Acme Labs')
        );
        $this->assertSame(
            'ACME PHARMA',
            CompanyNameNormalizer::canonical('Acme Pharma Inc d/b/a Acme Labs')
        );
        $this->assertSame(
            'ACME PHARMA',
            CompanyNameNormalizer::canonical('Acme Pharma Inc f/k/a Old Acme LLC')
        );
    }

    #[Test]
    public function a_division_of_uses_the_parent_entity(): void
    {
        $this->assertSame(
            'PFIZER',
            CompanyNameNormalizer::canonical('Hospira a division of Pfizer Inc')
        );
    }

    #[Test]
    public function trailing_location_fragments_are_stripped(): void
    {
        $this->assertSame(
            'PFIZER',
            CompanyNameNormalizer::canonical('Pfizer Inc, New York')
        );
    }

    #[Test]
    public function us_usa_and_united_states_variants_match(): void
    {
        $this->assertSame(
            CompanyNameNormalizer::canonical('Hikma Pharmaceuticals USA Inc.'),
            CompanyNameNormalizer::canonical('Hikma Pharmaceuticals U.S. Inc.')
        );
        $this->assertSame(
            CompanyNameNormalizer::canonical('Hikma Pharmaceuticals USA Inc.'),
            CompanyNameNormalizer::canonical('Hikma Pharmaceuticals United States Inc.')
        );
    }

    #[Test]
    public function multiple_suffix_combinations_collapse(): void
    {
        $this->assertSame(
            'FOO BAR',
            CompanyNameNormalizer::canonical('Foo Bar LLC Co Inc')
        );
        $this->assertSame(
            'FOO BAR',
            CompanyNameNormalizer::canonical('Foo Bar Corp Ltd')
        );
    }

    #[Test]
    public function glued_coltd_and_bio_pharma_variants_share_canonical(): void
    {
        $this->assertSame(
            'AMHWA BIOPHARM',
            CompanyNameNormalizer::canonical('Amhwa Biopharm Co.,Ltd')
        );
        $this->assertSame(
            'AMHWA BIOPHARM',
            CompanyNameNormalizer::canonical('AMHWA Biopharm CoLtd')
        );
        $this->assertSame(
            CompanyNameNormalizer::canonical('Inner Mongolia Zm Bio-Pharmaceutical Co.,Ltd'),
            CompanyNameNormalizer::canonical('Inner Mongolia ZM Biopharmaceutical CoLtd')
        );
        $this->assertSame(
            CompanyNameNormalizer::canonical('Zhejiang Gaorong Cosmetic Co., Ltd.'),
            CompanyNameNormalizer::canonical('ZHEJIANG GAORONG COSMETIC COLTD')
        );
    }

    #[Test]
    public function trailing_factory_and_sci_tech_align_with_base_name(): void
    {
        $this->assertSame(
            CompanyNameNormalizer::canonical('Tibet Cheezheng Tibetan Medicine Co. Ltd.'),
            CompanyNameNormalizer::canonical('Tibet Cheezheng Tibetan Medicine Factory Co Ltd')
        );
        $this->assertSame(
            CompanyNameNormalizer::canonical('Zhejiang Jiuzhou Pharmaceutical Co., Ltd.'),
            CompanyNameNormalizer::canonical('Zhejiang Jiuzhou Pharmaceutical Sci-Tech Co., Ltd')
        );
    }

    #[Test]
    public function partner_slug_still_keeps_existing_xttrium_slug(): void
    {
        $this->assertSame('xttrium-laboratories-inc', PartnerSlug::from('Xttrium Laboratories, Inc.'));
        $this->assertSame('xttrium-laboratories-inc', PartnerSlug::from('Xttrium Laboratories Inc;'));
        $this->assertSame('limited-brands-inc', PartnerSlug::from('Limited Brands Inc'));
    }
}
