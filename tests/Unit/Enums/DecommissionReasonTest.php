<?php

namespace Tests\Unit\Enums;

use App\Enums\DecommissionReason;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DecommissionReasonTest extends TestCase
{
    #[Test]
    public function each_reason_maps_to_the_locked_cbv_disposition(): void
    {
        $expected = [
            DecommissionReason::Destroyed->value => 'destroyed',
            DecommissionReason::Expired->value => 'expired',
            DecommissionReason::Recalled->value => 'recalled',
            DecommissionReason::Returned->value => 'returned',
            DecommissionReason::SuspectIllegitimate->value => 'inactive',
            DecommissionReason::QaRejectNeverShipped->value => 'disposed',
        ];

        foreach (DecommissionReason::cases() as $reason) {
            $this->assertSame($expected[$reason->value], $reason->dispositionLocal(), $reason->value);
            $this->assertSame(
                'urn:epcglobal:cbv:disp:'.$expected[$reason->value],
                $reason->dispositionUri(),
                $reason->value,
            );
            $this->assertNotSame('', $reason->label());
        }
    }

    #[Test]
    public function try_from_mixed_accepts_enum_and_string_codes(): void
    {
        $this->assertSame(
            DecommissionReason::Destroyed,
            DecommissionReason::tryFromMixed(DecommissionReason::Destroyed),
        );
        $this->assertSame(
            DecommissionReason::Expired,
            DecommissionReason::tryFromMixed('expired'),
        );
        $this->assertNull(DecommissionReason::tryFromMixed(null));
        $this->assertNull(DecommissionReason::tryFromMixed(''));
        $this->assertNull(DecommissionReason::tryFromMixed('not-a-reason'));
    }
}
