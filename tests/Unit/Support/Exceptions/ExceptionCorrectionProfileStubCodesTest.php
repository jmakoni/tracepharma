<?php

namespace Tests\Unit\Support\Exceptions;

use App\Support\Exceptions\ExceptionCorrectionProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExceptionCorrectionProfileStubCodesTest extends TestCase
{
    #[Test]
    public function operator_hidden_stub_codes_match_documented_stubs_and_orphans(): void
    {
        $codes = ExceptionCorrectionProfile::operatorHiddenStubCodes();

        $this->assertSame([
            'L2_L3_RECONCILIATION_FAILURE',
            'AUTO_DECOMMISSION_FAILED',
            'TIMING_INVERSION',
            // Superseded at runtime by SERIAL_SHIPPED_NOT_COMMISSIONED / MISSING_COMMISSIONING.
            'SHIP_BEFORE_COMMISSION',
        ], $codes);

        $this->assertNotContains('PARTNER_REJECTED_FILE', $codes);
        $this->assertNotContains('MISSING_MDN', $codes);
        $this->assertNotContains('LATE_MDN', $codes);
        $this->assertNotContains('L3_TRANSMISSION_FAILURE', $codes);
        $this->assertNotContains('UNCLASSIFIED', $codes);
        $this->assertNotContains('UNKNOWN_GTIN', $codes);

        $this->assertFalse(ExceptionCorrectionProfile::isOperatorHiddenStubCode('L3_TRANSMISSION_FAILURE'));
        $this->assertFalse(ExceptionCorrectionProfile::isOperatorHiddenStubCode('PARTNER_REJECTED_FILE'));
    }

    #[Test]
    public function is_operator_hidden_stub_code_normalizes_and_excludes_unclassified(): void
    {
        $this->assertFalse(ExceptionCorrectionProfile::isOperatorHiddenStubCode('missing_mdn'));
        $this->assertTrue(ExceptionCorrectionProfile::isOperatorHiddenStubCode(' TIMING_INVERSION '));
        $this->assertFalse(ExceptionCorrectionProfile::isOperatorHiddenStubCode('UNCLASSIFIED'));
        $this->assertFalse(ExceptionCorrectionProfile::isOperatorHiddenStubCode('UNKNOWN_GTIN'));
        $this->assertFalse(ExceptionCorrectionProfile::isOperatorHiddenStubCode(null));
        $this->assertFalse(ExceptionCorrectionProfile::isOperatorHiddenStubCode(''));
    }

    #[Test]
    public function internal_validation_failed_disables_waive_and_keeps_document_tools(): void
    {
        $profile = ExceptionCorrectionProfile::for('INTERNAL_VALIDATION_FAILED');

        $this->assertSame(ExceptionCorrectionProfile::FAMILY_DOCUMENT, $profile->family());
        $this->assertTrue($profile->showsDocumentTools());
        $this->assertFalse($profile->showsWaive());
        $this->assertSame(ExceptionCorrectionProfile::ACTION_FIX_DOCUMENT, $profile->primaryActionKey());
    }

    #[Test]
    public function extract_gtins_from_description_returns_every_gtin(): void
    {
        $this->assertSame(
            ['00301161111114'],
            ExceptionCorrectionProfile::extractGtinsFromDescription('GTIN not found in product master: 00301161111114'),
        );
        $this->assertSame(
            ['00301161111114', '00301162222221'],
            ExceptionCorrectionProfile::extractGtinsFromDescription(
                'GTIN not found in product master: 00301161111114; GTIN not found in product master: 00301162222221',
            ),
        );
        $this->assertSame('00301161111114', ExceptionCorrectionProfile::extractGtinFromDescription(
            'GTIN not found in product master: 00301161111114; GTIN not found in product master: 00301162222221',
        ));
        $this->assertSame([], ExceptionCorrectionProfile::extractGtinsFromDescription('No identifiers here.'));
    }
}
