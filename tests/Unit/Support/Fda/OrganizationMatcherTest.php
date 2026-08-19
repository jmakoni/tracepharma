<?php

namespace Tests\Unit\Support\Fda;

use App\Support\Fda\OrganizationMatch;
use App\Support\Fda\OrganizationMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OrganizationMatcherTest extends TestCase
{
    private OrganizationMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new OrganizationMatcher;
    }

    #[Test]
    public function exact_duns_links(): void
    {
        $match = $this->matcher->match('Other Name Inc', '123456789', [
            ['id' => 7, 'canonical_name' => 'SOMETHING ELSE', 'duns_number' => '123456789'],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_LINK, $match->action);
        $this->assertSame(7, $match->fdaOrganizationId);
        $this->assertSame('duns', $match->reason);
    }

    #[Test]
    public function exact_canonical_name_links(): void
    {
        $match = $this->matcher->match('Xttrium Laboratories, Inc.', null, [
            ['id' => 3, 'canonical_name' => 'XTTRIUM LABORATORIES', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_LINK, $match->action);
        $this->assertSame(3, $match->fdaOrganizationId);
        $this->assertSame('canonical_name', $match->reason);
    }

    #[Test]
    public function unique_high_fuzzy_auto_links(): void
    {
        $match = $this->matcher->match('Xttrium Laboratorie Inc', null, [
            ['id' => 3, 'canonical_name' => 'XTTRIUM LABORATORIES', 'duns_number' => null],
            ['id' => 9, 'canonical_name' => 'PFIZER', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_LINK, $match->action);
        $this->assertSame(3, $match->fdaOrganizationId);
        $this->assertSame('high_fuzzy', $match->reason);
        $this->assertGreaterThanOrEqual(OrganizationMatcher::HIGH_THRESHOLD, (float) $match->confidence);
    }

    #[Test]
    public function two_high_fuzzy_candidates_go_to_review(): void
    {
        $match = $this->matcher->match('Novo Nordisk Phar', null, [
            ['id' => 1, 'canonical_name' => 'NOVO NORDISK PHARMA', 'duns_number' => null],
            ['id' => 2, 'canonical_name' => 'NOVO NORDISK PHARM', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_REVIEW, $match->action);
        $this->assertSame('ambiguous_high', $match->reason);
    }

    #[Test]
    public function mid_band_fuzzy_creates_separate_organization(): void
    {
        $match = $this->matcher->match('Merck Sharp', null, [
            ['id' => 4, 'canonical_name' => 'MERCK SHARP DOHME', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function novel_name_auto_creates(): void
    {
        $match = $this->matcher->match('Completely Unrelated Widgets LLC', null, [
            ['id' => 4, 'canonical_name' => 'HIKMA PHARMACEUTICALS', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function empty_directory_creates(): void
    {
        $match = $this->matcher->match('First Org Inc', '111', []);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
    }

    #[Test]
    public function shared_generic_pharma_words_do_not_force_review(): void
    {
        $match = $this->matcher->match('Teva Pharmaceuticals Inc', null, [
            ['id' => 4, 'canonical_name' => 'MYLAN PHARMACEUTICALS', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function marketing_only_overlap_does_not_propose_review(): void
    {
        $match = $this->matcher->match('YS Marketing Inc', null, [
            ['id' => 3334, 'canonical_name' => 'ASC MARKETING', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
        $this->assertNull($match->fdaOrganizationId);
    }

    #[Test]
    public function prescription_center_only_overlap_does_not_propose_review(): void
    {
        $match = $this->matcher->match('Winships Prescription Center', null, [
            ['id' => 3742, 'canonical_name' => 'MAAG PRESCRIPTION CENTER', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
        $this->assertNull($match->fdaOrganizationId);
    }

    #[Test]
    public function critical_care_only_overlap_does_not_propose_review(): void
    {
        $match = $this->matcher->match('WG Critical Care LLC', null, [
            ['id' => 153, 'canonical_name' => 'PIRAMAL CRITICAL CARE', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
        $this->assertNull($match->fdaOrganizationId);
    }

    #[Test]
    public function single_shared_brand_token_in_mid_band_does_not_propose_review(): void
    {
        $cases = [
            ['Yuma Regional Medical Center', 'KADLEC REGIONAL MEDICAL CENTER'],
            ['Torrent Pharma Inc.', 'TORRENT PHARMACEUTICALS'],
            ['Teva API Inc.', 'TEVA NI'],
            ['Spartan Meds LLC', 'SPARTAN FEEDERS'],
            ['Takeda Pharmaceuticals America, Inc.', 'KOWA PHARMACEUTICALS AMERICA'],
            ['Thrifty Drug Stores, Inc.', 'H H DRUG STORES'],
            ['The Max Foundation', 'ABAKES FOUNDATION'],
            ['US MedSource, LLC', 'MEDSOURCE'],
            ['Rx Trading Corp', 'CORE TRADING'],
            ['Seattle Children\'s Pharma Link', 'SEATTLE CHILDREN S HOSPITAL'],
        ];

        foreach ($cases as [$input, $proposedCanonical]) {
            $match = $this->matcher->match($input, null, [
                ['id' => 1, 'canonical_name' => $proposedCanonical, 'duns_number' => null],
            ]);

            $this->assertSame(
                OrganizationMatch::ACTION_CREATE,
                $match->action,
                "Expected CREATE for [{$input}] vs [{$proposedCanonical}], got {$match->action}",
            );
        }
    }

    #[Test]
    public function sibling_brand_extensions_do_not_propose_review(): void
    {
        $cases = [
            ['Owell Naturals Llc', 'OWELL NATURALS BRAND'],
            ['Zhejiang Guoyao Aerosol Co., Ltd', 'ZHEJIANG GUOYAO JINGYUE AEROSOL'],
            ['Shandong Lukang Pharmaceutical Co., Ltd.', 'SHANDONG LUKANG SHELILE PHARMACEUTICAL'],
            ['Bausch Health Ireland Limited', 'BAUSCH LOMB IRELAND'],
            ['Yangzhou Jyr Daily Chemical Co., Ltd', 'YANGZHOU PERFECT DAILY CHEMICALS'],
            ['Shandong Jincheng Bio-Pharmaceutical Co., Ltd.', 'SHANDONG JINCHENG KUNLUN PHARMACEUTICAL'],
            ['Yangzhou H&R Plastic Daily Chemical Co., Ltd.', 'YANGZHOU PERFECT DAILY CHEMICALS'],
            ['Merck Sharp', 'MERCK SHARP DOHME'],
        ];

        foreach ($cases as [$input, $proposedCanonical]) {
            $match = $this->matcher->match($input, null, [
                ['id' => 1, 'canonical_name' => $proposedCanonical, 'duns_number' => null],
            ]);

            $this->assertSame(
                OrganizationMatch::ACTION_CREATE,
                $match->action,
                "Expected CREATE for [{$input}] vs [{$proposedCanonical}], got {$match->action}",
            );
        }
    }

    #[Test]
    public function two_shared_brand_tokens_no_longer_open_mid_band_review(): void
    {
        $match = $this->matcher->match('Merck Sharp', null, [
            ['id' => 4, 'canonical_name' => 'MERCK SHARP DOHME', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function industry_geo_and_sibling_lookalikes_do_not_propose_review(): void
    {
        $cases = [
            ['Puerto Rico General Distributing Company', 'GENERAL DISTRIBUTING'],
            ['Kent County Memorial Hospital', 'GARRETT COUNTY MEMORIAL HOSPITAL'],
            ['Logistical Resources, LLC', 'ABC LOGISTICAL RESOURCES'],
            ['Community Blood Center', 'LIFESOUTH COMMUNITY BLOOD CENTERS'],
            ['Sunset Potent Pain Relief', 'SUNSET PAIN RELIEF'],
            ['Guangdong Jiaoyu Biotechnology Co., Ltd.', 'GUANGDONG ZHOUBANG BIOTECHNOLOGY'],
            ['One Home Medical Equipment, Llc', 'ALICK S HOME MEDICAL EQUIPMENT'],
            ['Pari Respiratory Equipment, Inc.', 'MAVERICK OXYGEN RESPIRATORY EQUIPMENT'],
            ['Samanta Organics Private Limited', 'VANAMALI ORGANICS PRIVATE'],
            ['Edina Plastic Surgery,Ltd.', 'PREMIER PLASTIC SURGERY'],
            ['Respiratory Care Partners, Inc', 'RESPIRATORY PARTNERS'],
            ['Orient Pharma Co., Ltd. Yunlin Plant', 'OP NANOPHARMA CO LTD YUNLIN PLANT'],
            ['Hello Beautiful Skin & Acne', 'HELLO BEAUTIFUL SKIN STUDIO'],
            ['Catalent Germany Schorndorf Gmbh', 'CATALENT GERMANY EBERBACH GMBH'],
            ['Cspc Weisheng Pharmaceutical (Shijiazhuang)', 'CSPC ZHONGNUO PHARMACEUTICAL SHIJIAZHUANG'],
            ['Purity Cylinder Gases Inc', 'J M CYLINDER GASES'],
            ['Mips Cyclotron & Radiochemistry Facility', 'MAYO CLINIC PET RADIOCHEMISTRY FACILITY'],
            ['Laksh Fine Chem Private Limited', 'LEE FINE CHEM PRIVATE'],
            ['Eva Claire Cosmetics Co., Ltd.', 'CRYSTAL CLAIRE COSMETICS'],
            ['SCOTT\'S DENTAL SUPPLY LLC', 'MYCONE DENTAL SUPPLY'],
            ['Saad Enterprises, Inc.', 'MASCARENAS ENTERPRISES'],
            ['Rx Trading Corp', 'CORE TRADING'],
        ];

        foreach ($cases as [$input, $proposedCanonical]) {
            $match = $this->matcher->match($input, null, [
                ['id' => 1, 'canonical_name' => $proposedCanonical, 'duns_number' => null],
            ]);

            $this->assertSame(
                OrganizationMatch::ACTION_CREATE,
                $match->action,
                "Expected CREATE for [{$input}] vs [{$proposedCanonical}], got {$match->action}",
            );
        }
    }

    #[Test]
    public function leading_token_mismatch_blocks_high_fuzzy_link(): void
    {
        $match = $this->matcher->match('Logistical Resources, LLC', null, [
            ['id' => 1, 'canonical_name' => 'ABC LOGISTICAL RESOURCES', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function longer_labeler_links_to_unique_decrs_prefix(): void
    {
        $match = $this->matcher->match('Pfizer Laboratories Div Pfizer Inc', null, [
            ['id' => 11, 'canonical_name' => 'PFIZER', 'duns_number' => null],
            ['id' => 12, 'canonical_name' => 'MERCK', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_LINK, $match->action);
        $this->assertSame(11, $match->fdaOrganizationId);
        $this->assertSame('canonical_prefix', $match->reason);
    }

    #[Test]
    public function longest_unique_prefix_wins(): void
    {
        $match = $this->matcher->match('Pfizer Laboratories Specialty Inc', null, [
            ['id' => 11, 'canonical_name' => 'PFIZER', 'duns_number' => null],
            ['id' => 12, 'canonical_name' => 'PFIZER LABORATORIES', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_LINK, $match->action);
        $this->assertSame(12, $match->fdaOrganizationId);
        $this->assertSame('canonical_prefix', $match->reason);
    }

    #[Test]
    public function country_subsidiary_does_not_prefix_link_to_shorter_brand(): void
    {
        $match = $this->matcher->match('Fresenius Kabi Austria Gmbh', null, [
            ['id' => 76, 'canonical_name' => 'FRESENIUS KABI US', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function country_subsidiary_does_not_prefix_link_to_bare_brand(): void
    {
        $match = $this->matcher->match('Fresenius Kabi Deutschland Gmbh', null, [
            ['id' => 1, 'canonical_name' => 'FRESENIUS KABI', 'duns_number' => null],
        ]);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function strict_identity_links_on_exact_duns_even_when_names_differ(): void
    {
        $match = $this->matcher->match('Fresenius Kabi Austria GmbH', '076543210', [
            ['id' => 76, 'canonical_name' => 'FRESENIUS KABI', 'duns_number' => '076543210'],
        ], strictIdentity: true);

        $this->assertSame(OrganizationMatch::ACTION_LINK, $match->action);
        $this->assertSame(76, $match->fdaOrganizationId);
        $this->assertSame('duns', $match->reason);
    }

    #[Test]
    public function strict_identity_creates_when_duns_differs_despite_similar_name(): void
    {
        $match = $this->matcher->match('Fresenius Kabi Austria GmbH', '111222333', [
            ['id' => 76, 'canonical_name' => 'FRESENIUS KABI', 'duns_number' => '076543210'],
        ], strictIdentity: true);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function strict_identity_creates_when_duns_differs_despite_identical_canonical(): void
    {
        $match = $this->matcher->match('Acme Pharma Inc', '999888777', [
            ['id' => 1, 'canonical_name' => 'ACME PHARMA', 'duns_number' => '123456789'],
        ], strictIdentity: true);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function strict_identity_without_duns_skips_prefix_and_fuzzy(): void
    {
        $match = $this->matcher->match('Fresenius Kabi Austria GmbH', null, [
            ['id' => 76, 'canonical_name' => 'FRESENIUS KABI', 'duns_number' => null],
        ], strictIdentity: true);

        $this->assertSame(OrganizationMatch::ACTION_CREATE, $match->action);
        $this->assertSame('novel', $match->reason);
    }

    #[Test]
    public function strict_identity_without_duns_links_exact_canonical(): void
    {
        $match = $this->matcher->match('Acme Pharma Inc', null, [
            ['id' => 1, 'canonical_name' => 'ACME PHARMA', 'duns_number' => null],
        ], strictIdentity: true);

        $this->assertSame(OrganizationMatch::ACTION_LINK, $match->action);
        $this->assertSame(1, $match->fdaOrganizationId);
        $this->assertSame('canonical_name', $match->reason);
    }
}
