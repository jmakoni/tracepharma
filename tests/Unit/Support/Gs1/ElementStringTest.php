<?php

namespace Tests\Unit\Support\Gs1;

use App\Support\Gs1\ElementString;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ElementStringTest extends TestCase
{
    #[Test]
    public function it_parses_parenthesized_sgtin_with_lot_and_expiry(): void
    {
        $input = '(01)30301164005162(21)10000002877732(17)260731(10)LOT-A1';

        $ais = ElementString::parse($input);
        $this->assertSame('30301164005162', $ais['01']);
        $this->assertSame('10000002877732', $ais['21']);
        $this->assertSame('260731', $ais['17']);
        $this->assertSame('LOT-A1', $ais['10']);

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('30301164005162', $identity['gtin14']);
        $this->assertSame('10000002877732', $identity['serial']);
        $this->assertSame('01303011640051622110000002877732', $identity['ai_01_21']);
        $this->assertSame('LOT-A1', $identity['lot_number']);
        $this->assertSame('260731', $identity['expiry_yymmdd']);
    }

    #[Test]
    public function it_parses_unparenthesized_sgtin_serial_lot_and_expiry(): void
    {
        $input = '013030116400516221100000028777321726073110LOT-A1';

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('30301164005162', $identity['gtin14']);
        $this->assertSame('10000002877732', $identity['serial']);
        $this->assertSame('01303011640051622110000002877732', $identity['ai_01_21']);
        $this->assertSame('LOT-A1', $identity['lot_number']);
        $this->assertSame('260731', $identity['expiry_yymmdd']);
    }

    #[Test]
    public function it_parses_unparenthesized_sgtin_with_alphanumeric_serial(): void
    {
        $input = '013030116400516221ABC123XYZ';

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('30301164005162', $identity['gtin14']);
        $this->assertSame('ABC123XYZ', $identity['serial']);
        $this->assertSame('013030116400516221ABC123XYZ', $identity['ai_01_21']);
    }

    #[Test]
    public function it_keeps_embedded_10_inside_numeric_serial_without_fnc1_or_expiry(): void
    {
        // Warehouse 01+21 only; serial contains "10" and must not become AI 10 lot.
        $input = '01503011640231602110000000010393';

        $ais = ElementString::parse($input);
        $this->assertSame('50301164023160', $ais['01']);
        $this->assertSame('10000000010393', $ais['21']);
        $this->assertArrayNotHasKey('10', $ais);

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('50301164023160', $identity['gtin14']);
        $this->assertSame('10000000010393', $identity['serial']);
        $this->assertSame('01503011640231602110000000010393', $identity['ai_01_21']);
        $this->assertArrayNotHasKey('lot_number', $identity);
    }

    #[Test]
    public function it_keeps_alphanumeric_serials_that_contain_17(): void
    {
        // "17" inside an alphanumeric serial must not be read as AI 17 (expiry).
        $input = '013030116400516221AB1712345699';

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('AB1712345699', $identity['serial']);
        $this->assertArrayNotHasKey('expiry_yymmdd', $identity);
    }

    #[Test]
    public function it_keeps_numeric_serials_whose_17_is_not_followed_by_a_real_date(): void
    {
        // 123456 would be month 34 — not a YYMMDD, so the serial stays whole.
        $input = '0130301164005162219917123456';

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('9917123456', $identity['serial']);
        $this->assertArrayNotHasKey('expiry_yymmdd', $identity);
    }

    #[Test]
    public function it_parses_fnc1_delimited_expiry_and_lot_before_serial(): void
    {
        // (01)(17)(10)(21) order, FNC1 terminating each variable-length value.
        $input = "0130301164005162\x1D17260731\x1D10LOT-A1\x1D21SN2100017";

        $ais = ElementString::parse($input);
        $this->assertSame('30301164005162', $ais['01']);
        $this->assertSame('260731', $ais['17']);
        $this->assertSame('LOT-A1', $ais['10']);
        $this->assertSame('SN2100017', $ais['21']);

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('SN2100017', $identity['serial']);
        $this->assertSame('LOT-A1', $identity['lot_number']);
        $this->assertSame('260731', $identity['expiry_yymmdd']);
    }

    #[Test]
    public function fnc1_stops_a_lot_from_swallowing_the_following_serial(): void
    {
        // Without FNC1 the lot "LOT21X" and AI 21 cannot be told apart.
        $input = "01303011640051621017260731\x1D21SERIAL9";

        $ais = ElementString::parse($input);
        $this->assertSame('17260731', $ais['10']);
        $this->assertSame('SERIAL9', $ais['21']);
        $this->assertArrayNotHasKey('17', $ais);
    }

    #[Test]
    public function it_parses_parenthesized_expiry_and_lot_before_serial(): void
    {
        $input = '(01)30301164005162(17)260731(10)LOT-A1(21)SN99';

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('30301164005162', $identity['gtin14']);
        $this->assertSame('SN99', $identity['serial']);
        $this->assertSame('LOT-A1', $identity['lot_number']);
        $this->assertSame('260731', $identity['expiry_yymmdd']);
    }

    #[Test]
    public function it_parses_fixed_length_expiry_before_a_trailing_serial_without_fnc1(): void
    {
        // AI 17 is fixed length, so 01 + 17 + 21 needs no separator.
        $input = '0130301164005162172607312110000000010393';

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('260731', $identity['expiry_yymmdd']);
        $this->assertSame('10000000010393', $identity['serial']);
    }

    #[Test]
    public function parenthesized_values_do_not_absorb_the_fnc1_separator(): void
    {
        $input = "(01)30301164005162\x1D(21)SN99";

        $ais = ElementString::parse($input);
        $this->assertSame('30301164005162', $ais['01']);
        $this->assertSame('SN99', $ais['21']);
    }

    #[Test]
    public function normalize_segments_keeps_fnc1_while_stripping_other_noise(): void
    {
        $input = "]C1\x0201 30301164005162\x1D21 SN99\x0D\x0A";

        $this->assertSame('013030116400516221SN99', ElementString::normalize($input));
        $this->assertSame("0130301164005162\x1D21SN99", ElementString::normalizeSegments($input));
    }

    #[Test]
    public function it_encodes_sgtin_with_expiry_and_lot_in_01_21_17_10_order(): void
    {
        $encoded = ElementString::encodeSgtin(
            '50301164005081',
            '10000000172110',
            '511115A',
            '271031',
        );

        $this->assertSame('015030116400508121100000001721101727103110511115A', $encoded);

        $identity = ElementString::sgtinIdentity($encoded);
        $this->assertNotNull($identity);
        $this->assertSame('50301164005081', $identity['gtin14']);
        $this->assertSame('10000000172110', $identity['serial']);
        $this->assertSame('01503011640050812110000000172110', $identity['ai_01_21']);
        $this->assertSame('511115A', $identity['lot_number']);
        $this->assertSame('271031', $identity['expiry_yymmdd']);
    }

    #[Test]
    public function it_encodes_sgtin_as_01_21_when_lot_and_expiry_are_absent(): void
    {
        $this->assertSame(
            '01503011640050812110000000172110',
            ElementString::encodeSgtin('50301164005081', '10000000172110'),
        );
    }

    #[Test]
    public function it_does_not_append_lot_without_expiry(): void
    {
        $this->assertSame(
            '01503011640050812110000000172110',
            ElementString::encodeSgtin('50301164005081', '10000000172110', '511115A'),
        );
    }

    #[Test]
    public function it_appends_expiry_without_lot(): void
    {
        $encoded = ElementString::encodeSgtin(
            '50301164005081',
            '10000000172110',
            null,
            '271031',
        );

        $this->assertSame('0150301164005081211000000017211017271031', $encoded);

        $identity = ElementString::sgtinIdentity($encoded);
        $this->assertNotNull($identity);
        $this->assertSame('271031', $identity['expiry_yymmdd']);
        $this->assertArrayNotHasKey('lot_number', $identity);
    }

    #[Test]
    public function it_accepts_three_sscc_scan_forms(): void
    {
        $sscc18 = '003011610012354038';
        $ai00 = '00003011610012354038';

        foreach ([$sscc18, $ai00, '(00)'.$sscc18] as $input) {
            $identity = ElementString::ssccIdentity($input);
            $this->assertNotNull($identity, "Failed for input: {$input}");
            $this->assertSame($sscc18, $identity['sscc18']);
            $this->assertSame($ai00, $identity['ai_00']);
        }
    }

    #[Test]
    public function it_strips_whitespace_and_fnc1_without_uppercasing_serials(): void
    {
        $input = "01 30301164005162\x1D21abcSerial";

        $this->assertSame('013030116400516221abcSerial', ElementString::normalize($input));

        $identity = ElementString::sgtinIdentity($input);
        $this->assertNotNull($identity);
        $this->assertSame('abcSerial', $identity['serial']);
    }

    #[Test]
    public function it_strips_aim_prefix_controls_and_zero_width_from_scans(): void
    {
        $input = "]C1\x02(01)30301164005162\x1D(21)SN\x1E99\x03\x0D\x0A";

        $this->assertSame('(01)30301164005162(21)SN99', ElementString::normalize($input));

        $withZwsp = "]e0\u{200B}013030116400516221serialOne";
        $this->assertSame('013030116400516221serialOne', ElementString::normalize($withZwsp));
    }
}
