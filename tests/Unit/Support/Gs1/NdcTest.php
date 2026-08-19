<?php

namespace Tests\Unit\Support\Gs1;

use App\Support\Gs1\Ndc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class NdcTest extends TestCase
{
    #[Test]
    public function it_normalizes_dashed_and_eleven_digit_forms_to_the_same_ndc11(): void
    {
        $this->assertSame('00116407501', Ndc::toNdc11('0116-4075-01'));
        $this->assertSame('00116407501', Ndc::toNdc11('00116407501'));
        $this->assertTrue(Ndc::equals('0116-4075-01', '00116407501'));
    }

    #[Test]
    public function it_refuses_to_guess_segment_boundaries_for_a_bare_ten_digit_ndc(): void
    {
        // 0116407501 could be 4-4-2, 5-3-2 or 5-4-1 — left-padding would invent a labeler.
        $this->assertNull(Ndc::toNdc11('0116407501'));
        $this->assertNull(Ndc::toNdc11('1234567890'));
        $this->assertFalse(Ndc::equals('0116-4075-01', '0116407501'));
    }

    #[Test]
    public function it_refuses_dashed_values_that_are_not_an_fda_or_hipaa_segment_shape(): void
    {
        // Padding these would invent labeler / product / package digits.
        $this->assertNull(Ndc::toNdc11('116-4075-01'));      // 3-4-2
        $this->assertNull(Ndc::toNdc11('0116-407-01'));      // 4-3-2
        $this->assertNull(Ndc::toNdc11('0116-4075-1'));      // 4-4-1
        $this->assertNull(Ndc::toNdc11('123456-4075-01'));   // 6-4-2
        $this->assertNull(Ndc::toNdc11('0116--01'));         // empty product segment

        // The four accepted shapes still read.
        $this->assertSame('00116407501', Ndc::toNdc11('0116-4075-01'));   // 4-4-2
        $this->assertSame('12345067890', Ndc::toNdc11('12345-678-90'));   // 5-3-2
        $this->assertSame('12345678901', Ndc::toNdc11('12345-6789-1'));   // 5-4-1
        $this->assertSame('00116400540', Ndc::toNdc11('00116-4005-40'));  // 5-4-2
    }

    #[Test]
    public function it_lists_every_ndc11_a_bare_ten_digit_ndc_could_expand_to(): void
    {
        $candidates = Ndc::ndc11CandidatesFromTenDigits('0116407501');

        $this->assertSame([
            '00116407501', // 4-4-2 → labeler padded
            '01164007501', // 5-3-2 → product padded
            '01164075001', // 5-4-1 → package padded
        ], $candidates);

        $this->assertSame([], Ndc::ndc11CandidatesFromTenDigits('12345'));
        $this->assertSame([], Ndc::ndc11CandidatesFromTenDigits(null));
    }

    #[Test]
    public function derive_prefers_the_package_ndc_over_the_product_ndc(): void
    {
        $this->assertSame('00116407501', Ndc::derive('0116-4075-01', '0116-4075'));
        $this->assertSame('12345067890', Ndc::derive(null, '12345-678-90'));
        $this->assertNull(Ndc::derive('0116407501', '0116-4075'));
        $this->assertNull(Ndc::derive(null, null));
    }

    #[Test]
    public function it_converts_a_4_4_2_dashed_ndc(): void
    {
        $this->assertSame('00116407501', Ndc::toNdc11('0116-4075-01'));
        $this->assertSame('00116-4075-01', Ndc::formatDisplay('0116-4075-01'));
    }

    #[Test]
    public function it_converts_a_5_3_2_dashed_ndc(): void
    {
        $this->assertSame('12345067890', Ndc::toNdc11('12345-678-90'));
    }

    #[Test]
    public function it_converts_a_5_4_1_dashed_ndc(): void
    {
        $this->assertSame('12345678901', Ndc::toNdc11('12345-6789-1'));
    }

    #[Test]
    public function it_returns_null_for_invalid_values(): void
    {
        $this->assertNull(Ndc::toNdc11(null));
        $this->assertNull(Ndc::toNdc11(''));
        $this->assertNull(Ndc::toNdc11('12345'));
        $this->assertNull(Ndc::toNdc11('123456789012'));
        $this->assertNull(Ndc::toNdc11('abc-def-ghi'));
        $this->assertNull(Ndc::formatDisplay('not-an-ndc'));
        $this->assertFalse(Ndc::equals('0116-4075-01', '12345-6789-1'));
    }

    #[Test]
    public function format_package_display_still_reads_dashed_and_ndc11_input(): void
    {
        // Bare 10-digit input is ambiguous and no longer guessed.
        $this->assertNull(Ndc::formatPackageDisplay('0116400540'));
        $this->assertSame('0116-4005-40', Ndc::formatPackageDisplay('00116400540'));
        $this->assertSame('0116-4005-40', Ndc::formatPackageDisplay('0116-4005-40'));
    }

    #[Test]
    public function it_strips_non_digits(): void
    {
        $this->assertSame('0116407501', Ndc::digits('0116-4075-01'));
    }

    #[Test]
    public function it_lists_fda_style_package_ndc_candidates_for_ndc11(): void
    {
        $candidates = Ndc::packageNdcCandidates('00116402316');

        $this->assertContains('00116-4023-16', $candidates);
        $this->assertContains('0116-4023-16', $candidates);
        $this->assertTrue(Ndc::equals('0116-4023-16', '00116402316'));
    }

    #[Test]
    public function format_package_display_uses_authoritative_listing_as_is(): void
    {
        $this->assertSame(
            '0116-4005-40',
            Ndc::formatPackageDisplay('00116400540', '0116-4005-40'),
        );
    }

    #[Test]
    public function format_package_display_keeps_dashed_source_as_is(): void
    {
        $this->assertSame('12345-678-90', Ndc::formatPackageDisplay('12345-678-90'));
        $this->assertSame('0116-4005-40', Ndc::formatPackageDisplay('0116-4005-40'));
    }

    #[Test]
    public function format_package_display_reverses_hipaa_ndc11_to_fda_dashed(): void
    {
        // 4-4-2 (labeler padded)
        $this->assertSame('0116-4005-40', Ndc::formatPackageDisplay('00116400540'));
        $this->assertSame('0116-4005-40', Ndc::formatPackageDisplay('00116-4005-40'));
        $this->assertSame('0116-4023-16', Ndc::formatPackageDisplay('00116402316'));

        // 5-3-2 (product padded)
        $this->assertSame('12345-678-90', Ndc::formatPackageDisplay('12345067890'));

        // 5-4-1 (package padded)
        $this->assertSame('12345-6789-1', Ndc::formatPackageDisplay('12345678901'));
    }
}
