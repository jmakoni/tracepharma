<?php

namespace Tests\Unit\Support;

use App\Support\InboundConnectionPartnerRoutingSync;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Inbound routing matches this value against the GLN read out of the sender's SBDH.
 * Anything else stored here is a mapping that looks configured and routes nothing.
 */
class InboundConnectionPartnerRoutingSyncTest extends TestCase
{
    #[Test]
    public function it_keeps_a_real_gln_and_strips_its_separators(): void
    {
        $this->assertSame(
            '0614141000005',
            InboundConnectionPartnerRoutingSync::normalizeSenderGln('0614141000005'),
        );

        $this->assertSame(
            '0614141000005',
            InboundConnectionPartnerRoutingSync::normalizeSenderGln(' 0614 141-000005 '),
        );
    }

    #[Test]
    public function it_drops_anything_that_is_not_thirteen_digits(): void
    {
        foreach (['06141410000', '06141410000051', 'ACME-WAREHOUSE', '', '   '] as $value) {
            $this->assertNull(
                InboundConnectionPartnerRoutingSync::normalizeSenderGln($value),
                "Expected {$value} to be dropped.",
            );
        }
    }

    #[Test]
    public function it_drops_thirteen_digits_that_fail_the_gs1_check_digit(): void
    {
        $this->assertNull(InboundConnectionPartnerRoutingSync::normalizeSenderGln('0614141000006'));
    }

    #[Test]
    public function it_drops_values_that_are_not_strings_or_numbers(): void
    {
        $this->assertNull(InboundConnectionPartnerRoutingSync::normalizeSenderGln(null));
        $this->assertNull(InboundConnectionPartnerRoutingSync::normalizeSenderGln([]));
        $this->assertNull(InboundConnectionPartnerRoutingSync::normalizeSenderGln(true));
    }
}
