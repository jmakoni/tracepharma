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
            'PARTNER_REJECTED_FILE',
            'MISSING_MDN',
            'LATE_MDN',
            'L2_L3_RECONCILIATION_FAILURE',
            'L3_TRANSMISSION_FAILURE',
            'AUTO_DECOMMISSION_FAILED',
            'TIMING_INVERSION',
            'SHIP_BEFORE_COMMISSION',
        ], $codes);

        $this->assertNotContains('UNCLASSIFIED', $codes);
        $this->assertNotContains('UNKNOWN_GTIN', $codes);
    }

    #[Test]
    public function is_operator_hidden_stub_code_normalizes_and_excludes_unclassified(): void
    {
        $this->assertTrue(ExceptionCorrectionProfile::isOperatorHiddenStubCode('missing_mdn'));
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
}
