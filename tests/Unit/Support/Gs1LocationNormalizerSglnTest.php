<?php

namespace Tests\Unit\Support;

use App\Support\Gs1LocationNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Gs1LocationNormalizerSglnTest extends TestCase
{
    #[Test]
    public function sgln_urn_normalizes_to_the_gln_not_concatenated_digits(): void
    {
        $this->assertSame(
            '0301160000009',
            Gs1LocationNormalizer::normalize('urn:epc:id:sgln:030116.000000.0'),
        );
        $this->assertSame(
            '1200202228045',
            Gs1LocationNormalizer::normalize('urn:epc:id:sgln:120020.222804.0'),
        );
    }
}
