<?php

namespace Tests\Unit\Gs1;

use App\Support\Gs1\SglnRules;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SglnRulesTest extends TestCase
{
    #[Test]
    public function it_accepts_an_sgln_that_encodes_the_records_own_gln(): void
    {
        $this->assertNull(SglnRules::check('urn:epc:id:sgln:0614141.00000.0', '0614141000005'));
        $this->assertNull(SglnRules::check('urn:epc:id:sgln:061414.100000.7', '0614141000005'));
    }

    #[Test]
    public function it_rejects_the_legacy_two_segment_form(): void
    {
        $message = SglnRules::check('urn:epc:id:sgln:061414100000.0', '0614141000005');

        $this->assertNotNull($message);
        $this->assertStringContainsString('Pure Identity', $message);
    }

    #[Test]
    public function it_rejects_an_sgln_that_belongs_to_another_location(): void
    {
        $message = SglnRules::check('urn:epc:id:sgln:0301160.00000.1', '0614141000005');

        $this->assertNotNull($message);
        $this->assertStringContainsString('0301160000009', $message);
    }

    #[Test]
    public function it_accepts_any_valid_sgln_when_no_gln_is_on_the_record_yet(): void
    {
        $this->assertNull(SglnRules::check('urn:epc:id:sgln:0301160.00000.1', null));
        $this->assertNull(SglnRules::check('urn:epc:id:sgln:0301160.00000.1', ''));
    }

    #[Test]
    public function blank_passes_so_the_field_stays_optional(): void
    {
        $this->assertNull(SglnRules::check(null, '0614141000005'));
        $this->assertNull(SglnRules::check('   ', '0614141000005'));
    }
}
