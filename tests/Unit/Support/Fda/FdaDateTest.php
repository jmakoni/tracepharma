<?php

namespace Tests\Unit\Support\Fda;

use App\Support\Fda\FdaDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FdaDateTest extends TestCase
{
    #[Test]
    #[DataProvider('usLicenseExpirations')]
    public function parses_us_wdd_license_expiration_formats(string $input, string $expected): void
    {
        $this->assertSame($expected, FdaDate::toDateString($input));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function usLicenseExpirations(): array
    {
        return [
            ['09-21-2026', '2026-09-21'],
            ['9-21-2026', '2026-09-21'],
            ['09/21/2026', '2026-09-21'],
            ['9/21/2026', '2026-09-21'],
            ['12/31/2027', '2027-12-31'],
            ['2026-09-21', '2026-09-21'],
            [' 09-21-2026 ', '2026-09-21'],
        ];
    }

    #[Test]
    public function empty_expiration_is_null(): void
    {
        $this->assertNull(FdaDate::toDateString(''));
        $this->assertNull(FdaDate::toDateString(null));
        $this->assertNull(FdaDate::toDateString('   '));
    }
}
