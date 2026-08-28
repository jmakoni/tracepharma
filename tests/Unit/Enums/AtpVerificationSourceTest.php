<?php

namespace Tests\Unit\Enums;

use App\Enums\AtpVerificationSource;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Manual Pulse / OCI evidence sources are partner-supplied diligence records only —
 * not a Pulse API integration and not a claim that TracePharma is Pulse-listed.
 */
class AtpVerificationSourceTest extends TestCase
{
    #[Test]
    public function pulse_and_oci_partner_evidence_cases_exist_with_labels(): void
    {
        $this->assertSame('pulse_partner_evidence', AtpVerificationSource::PulsePartnerEvidence->value);
        $this->assertSame(
            'NABP Pulse (partner-supplied evidence)',
            AtpVerificationSource::PulsePartnerEvidence->label(),
        );

        $this->assertSame('oci_partner_evidence', AtpVerificationSource::OciPartnerEvidence->value);
        $this->assertSame(
            'OCI / directory (partner-supplied evidence)',
            AtpVerificationSource::OciPartnerEvidence->label(),
        );
    }

    #[Test]
    public function options_include_pulse_and_oci_partner_evidence(): void
    {
        $options = AtpVerificationSource::options();

        $this->assertArrayHasKey(AtpVerificationSource::PulsePartnerEvidence->value, $options);
        $this->assertSame(
            'NABP Pulse (partner-supplied evidence)',
            $options[AtpVerificationSource::PulsePartnerEvidence->value],
        );

        $this->assertArrayHasKey(AtpVerificationSource::OciPartnerEvidence->value, $options);
        $this->assertSame(
            'OCI / directory (partner-supplied evidence)',
            $options[AtpVerificationSource::OciPartnerEvidence->value],
        );
    }
}
