<?php

namespace Tests\Unit\Support\Fda;

use App\Support\Fda\DeaRegistration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DeaRegistrationTest extends TestCase
{
    #[Test]
    public function parse_accepts_prefixed_dea_and_rejects_gln_shaped_bare_digits(): void
    {
        $this->assertSame('AB1234567', DeaRegistration::parseFromLocationToken('DEA:AB1234567'));
        $this->assertSame('AB1234567', DeaRegistration::parseFromLocationToken('dea/ab-1234567'));
        $this->assertSame('AB1234567', DeaRegistration::parseFromLocationToken('urn:epc:id:dea:AB1234567'));
        $this->assertNull(DeaRegistration::parseFromLocationToken('0614141000005'));
    }
}
