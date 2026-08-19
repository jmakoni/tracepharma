<?php

namespace Tests\Unit\Support\Fda;

use App\Support\Fda\WddOrganizationName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WddOrganizationNameTest extends TestCase
{
    #[Test]
    #[DataProvider('dcNames')]
    public function strips_dc_site_suffixes(string $input, string $expected): void
    {
        $this->assertSame($expected, WddOrganizationName::fromFacilityName($input));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function dcNames(): array
    {
        return [
            ['Owens & Minor Distribution -Bangor DC 96', 'Owens & Minor Distribution'],
            ['Owens & Minor Distribution - Chicago DC 98', 'Owens & Minor Distribution'],
            ['Owens & Minor Distribution - Southern California DC 65', 'Owens & Minor Distribution'],
            ['Owens & Minor Distribution - Des Moines ISC DC48', 'Owens & Minor Distribution'],
            ['Owens & Minor Distribution - COLUMBUS SLC DC91', 'Owens & Minor Distribution'],
            ['Owens & Minor Distribution - Sioux Falls DC06', 'Owens & Minor Distribution'],
            ['Owens & Minor Distribution - Warrendale DC93', 'Owens & Minor Distribution'],
            ['  Owens & Minor Distribution - Tulsa DC 16  ', 'Owens & Minor Distribution'],
            ['Plain Wholesaler LLC', 'Plain Wholesaler LLC'],
            ['', ''],
        ];
    }
}
