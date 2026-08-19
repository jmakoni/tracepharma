<?php

namespace Tests\Unit\Support\Exceptions;

use App\Support\Exceptions\DscsaSectionReference;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DscsaSectionReferenceTest extends TestCase
{
    #[Test]
    public function missing_transaction_statement_cites_ts_section(): void
    {
        $this->assertSame(
            'DSCSA §582.1(a)(6) Transaction Statement (TS)',
            DscsaSectionReference::label('MISSING_DSCSA_STATEMENT'),
        );
    }

    #[Test]
    public function verification_failed_adds_quarantine_actions(): void
    {
        $actions = DscsaSectionReference::receiverActions('VERIFICATION_FAILED', true);

        $this->assertContains('Shipment placed on COMPLIANCE HOLD — not Ready to receive', $actions);
        $this->assertContains('Affected unit(s) quarantined — do not dispense', $actions);
    }

    #[Test]
    public function unknown_codes_fall_back_without_inventing_a_section(): void
    {
        $this->assertSame(
            'DSCSA product tracing and transaction document requirements',
            DscsaSectionReference::label('UNCLASSIFIED'),
        );
        $this->assertSame([], DscsaSectionReference::receiverActions('UNCLASSIFIED', false));
    }

    #[Test]
    public function invalid_sscc_check_digit_cites_the_same_ti_section_as_gtin(): void
    {
        $this->assertSame(
            DscsaSectionReference::label('INVALID_GTIN_CHECK_DIGIT'),
            DscsaSectionReference::label('INVALID_SSCC_CHECK_DIGIT'),
        );
        $this->assertSame(
            'DSCSA §582.1(a)(5) Transaction Information (TI)',
            DscsaSectionReference::label('INVALID_SSCC_CHECK_DIGIT'),
        );
    }
}
