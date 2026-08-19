<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Epcis;

use App\Domain\Epcis\Data\SerializedUnitData;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SerializedUnitDataTest extends TestCase
{
    #[Test]
    public function it_builds_a_valid_serialized_unit_with_epc_uri(): void
    {
        $unit = SerializedUnitData::fromValidated([
            'gtin' => '30301164005162',
            'serial' => '10000002877732',
            'lot' => 'LOT-A',
            'expiry' => new DateTimeImmutable('2027-01-31', new DateTimeZone('UTC')),
            'companyPrefix' => '030116',
        ]);

        $this->assertSame('30301164005162', $unit->gtin);
        $this->assertSame('urn:epc:id:sgtin:030116.3400516.10000002877732', $unit->epcUri);
        $this->assertSame('2027-01-31', $unit->expiry->format('Y-m-d'));
    }

    #[Test]
    public function it_rejects_invalid_gtin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SerializedUnitData::fromValidated([
            'gtin' => '30301164005160',
            'serial' => '1',
            'lot' => 'LOT',
            'expiry' => '2027-01-31',
        ]);
    }

    #[Test]
    public function it_rejects_epc_uri_mismatched_to_gtin_or_serial(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('epcUri does not match gtin and serial');

        SerializedUnitData::fromValidated([
            'gtin' => '30301164005162',
            'serial' => 'AAAA',
            'lot' => 'LOT-A',
            'expiry' => '2027-01-31',
            'epcUri' => 'urn:epc:id:sgtin:030116.3400516.10000002877732',
        ]);
    }
}
