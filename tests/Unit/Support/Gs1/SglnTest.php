<?php

namespace Tests\Unit\Support\Gs1;

use App\Support\Gs1\Sgln;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SglnTest extends TestCase
{
    #[Test]
    public function to_urn_requires_known_company_prefix_length(): void
    {
        $this->assertSame(
            'urn:epc:id:sgln:0096295.00000.0',
            Sgln::toUrn('0096295000009', 7),
        );

        $this->assertNull(Sgln::toUrn('0096295000009', 5));
        $this->assertNull(Sgln::toUrn('not-a-gln', 7));
    }

    #[Test]
    public function resolve_urn_matches_hint_or_candidates_without_guessing_prefix(): void
    {
        $gln = '0096295000009';
        $hint = 'urn:epc:id:sgln:0096295.00000.0';

        $this->assertSame($hint, Sgln::resolveUrn($gln, $hint));
        $this->assertSame(
            $hint,
            Sgln::resolveUrn($gln, null, [
                'urn:epc:id:sgln:030116.000000.0',
                $hint,
            ]),
        );
        $this->assertNull(Sgln::resolveUrn($gln, 'urn:epc:id:sgln:030116.000000.0'));
        $this->assertNull(Sgln::resolveUrn($gln));
    }
}
