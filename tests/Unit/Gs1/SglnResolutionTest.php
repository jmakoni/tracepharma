<?php

namespace Tests\Unit\Gs1;

use App\Support\Gs1\SglnResolution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The SGLN we author is a location's identity on a DSCSA transaction record. These
 * tests pin the one rule that keeps it truthful: it comes from the partner or from
 * our own company prefix, and from nowhere else.
 */
class SglnResolutionTest extends TestCase
{
    private const OUR_PREFIX = '0614141';

    private const OUR_GLN = '0614141000005';

    private const PARTNER_GLN = '0301160000009';

    #[Test]
    public function it_uses_an_sgln_on_record_for_the_location(): void
    {
        $this->assertSame(
            'urn:epc:id:sgln:0301160.00000.1',
            SglnResolution::resolve(self::PARTNER_GLN, ['urn:epc:id:sgln:0301160.00000.1'], self::OUR_PREFIX),
        );
    }

    #[Test]
    public function a_recorded_sgln_wins_over_our_own_company_prefix(): void
    {
        // The partner splits our GLN where their own record says it splits; when they
        // have told us, their split is the one that identifies the location.
        $this->assertSame(
            'urn:epc:id:sgln:061414.100000.0',
            SglnResolution::resolve(self::OUR_GLN, ['urn:epc:id:sgln:061414.100000.0'], self::OUR_PREFIX),
        );
    }

    #[Test]
    public function it_ignores_a_candidate_that_encodes_a_different_location(): void
    {
        $this->assertNull(
            SglnResolution::resolve(self::PARTNER_GLN, ['urn:epc:id:sgln:0614141.00000.0'], null),
        );
    }

    #[Test]
    public function it_ignores_the_legacy_two_segment_column_value(): void
    {
        // What the generated column produced: urn:epc:id:sgln:{first 12 digits}.0.
        $this->assertNull(
            SglnResolution::resolve(self::PARTNER_GLN, ['urn:epc:id:sgln:030116000000.0'], null),
        );
    }

    #[Test]
    public function it_encodes_a_gln_issued_under_our_own_company_prefix(): void
    {
        $this->assertSame(
            'urn:epc:id:sgln:0614141.00000.0',
            SglnResolution::resolve(self::OUR_GLN, [], self::OUR_PREFIX),
        );
    }

    #[Test]
    public function it_encodes_a_gln_under_an_additional_known_prefix(): void
    {
        // A sister warehouse GLN that is not under the organization prefix, but sits
        // under a company prefix we already recorded on another of our facilities.
        $this->assertSame(
            'urn:epc:id:sgln:0301160.00000.0',
            SglnResolution::resolve(self::PARTNER_GLN, [], self::OUR_PREFIX, ['0301160']),
        );
    }

    #[Test]
    public function it_encodes_using_company_prefix_length_when_the_gln_is_not_under_the_prefix(): void
    {
        $this->assertSame(
            'urn:epc:id:sgln:0301160.00000.0',
            SglnResolution::fromPrefixLength(self::PARTNER_GLN, self::OUR_PREFIX),
        );
        $this->assertSame(
            'urn:epc:id:sgln:0301160.00000.7',
            SglnResolution::fromPrefixLength(self::PARTNER_GLN, self::OUR_PREFIX, '7'),
        );
    }

    #[Test]
    public function from_prefix_length_returns_null_without_a_usable_prefix(): void
    {
        $this->assertNull(SglnResolution::fromPrefixLength(self::PARTNER_GLN, null));
        $this->assertNull(SglnResolution::fromPrefixLength(self::PARTNER_GLN, '06141'));
        $this->assertNull(SglnResolution::fromPrefixLength(null, self::OUR_PREFIX));
    }

    #[Test]
    public function it_returns_null_without_a_company_prefix_or_a_recorded_sgln(): void
    {
        $this->assertNull(SglnResolution::resolve(self::OUR_GLN, [], null));
        $this->assertNull(SglnResolution::resolve(self::OUR_GLN, [], ''));
    }

    #[Test]
    public function it_refuses_a_company_prefix_outside_the_gs1_range(): void
    {
        // GS1 company prefixes run 6–11 digits; anything else cannot describe a split.
        $this->assertNull(SglnResolution::fromCompanyPrefix(self::OUR_GLN, '06141'));
        $this->assertNull(SglnResolution::fromCompanyPrefix(self::OUR_GLN, '061414100000'));
    }

    #[Test]
    public function it_keeps_the_sub_location_extension_it_is_handed(): void
    {
        // The extension names a dock door inside the GLN; re-encoding on a new company
        // prefix moves where the digits split, not which door they lead to.
        $this->assertSame(
            'urn:epc:id:sgln:0614141.00000.7',
            SglnResolution::fromCompanyPrefix(self::OUR_GLN, self::OUR_PREFIX, '7'),
        );
    }

    #[Test]
    public function it_refuses_an_extension_that_is_not_a_number(): void
    {
        $this->assertNull(SglnResolution::fromCompanyPrefix(self::OUR_GLN, self::OUR_PREFIX, 'dock-7'));
        $this->assertNull(SglnResolution::fromCompanyPrefix(self::OUR_GLN, self::OUR_PREFIX, ''));
    }

    #[Test]
    public function it_returns_null_for_anything_that_is_not_a_gln(): void
    {
        $this->assertNull(SglnResolution::resolve(null, [], self::OUR_PREFIX));
        $this->assertNull(SglnResolution::resolve('', [], self::OUR_PREFIX));
        $this->assertNull(SglnResolution::resolve('06141410000', [], self::OUR_PREFIX));
    }

    #[Test]
    public function it_normalizes_separators_in_the_gln_and_the_prefix(): void
    {
        $this->assertSame(
            'urn:epc:id:sgln:0614141.00000.0',
            SglnResolution::resolve('0614 141-000005', [], '0614-141'),
        );
    }

    #[Test]
    public function urn_candidates_keeps_only_parseable_three_segment_urns(): void
    {
        $this->assertSame(
            ['urn:epc:id:sgln:0614141.00000.0'],
            SglnResolution::urnCandidates([
                null,
                '',
                42,
                'urn:epc:id:sgln:061414100000.0',
                'urn:epc:id:sgln:0614141.00000.0',
            ]),
        );
    }
}
