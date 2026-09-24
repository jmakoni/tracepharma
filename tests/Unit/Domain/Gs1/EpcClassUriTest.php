<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Gs1;

use App\Domain\Gs1\EpcClassUri;
use App\Domain\Gs1\SgtinUri;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EpcClassUriTest extends TestCase
{
    #[Test]
    public function it_accepts_sgtin_idpat(): void
    {
        $uri = EpcClassUri::fromString('urn:epc:idpat:sgtin:030116.3400516.*');

        $this->assertSame('urn:epc:idpat:sgtin:030116.3400516.*', $uri->toString());
    }

    #[Test]
    public function it_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EpcClassUri::fromString('not-a-valid-class');
    }

    #[Test]
    public function it_builds_lgtin_from_sgtin_and_lot(): void
    {
        $sgtin = SgtinUri::fromUrn('urn:epc:id:sgtin:030116.5200116.00000000413101');

        $this->assertSame(
            'urn:epc:class:lgtin:030116.5200116.LOT-A',
            EpcClassUri::fromSgtinAndLot($sgtin, 'LOT-A')->toString(),
        );
    }
}
