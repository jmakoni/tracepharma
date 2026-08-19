<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Gs1;

use App\Domain\Gs1\EpcClassUri;
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
}
