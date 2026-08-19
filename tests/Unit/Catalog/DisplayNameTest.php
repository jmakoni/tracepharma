<?php

namespace Tests\Unit\Catalog;

use App\Support\Catalog\DisplayName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DisplayNameTest extends TestCase
{
    #[Test]
    #[DataProvider('cleanProvider')]
    public function cleans_prefixes_and_title_cases(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, DisplayName::clean($input));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function cleanProvider(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', ''],
            'plain' => ['Acme Pharma', 'Acme Pharma'],
            'uppercase' => ['ACME PHARMA INC.', 'Acme Pharma Inc.'],
            'leading dash space' => ['- INDUSTRIAL WELDING', 'Industrial Welding'],
            'leading colon space' => [': Preferred Pharmaceuticals Inc.', 'Preferred Pharmaceuticals Inc.'],
            'repeated prefixes' => ['- : - Foo', 'Foo'],
            'alpha dot titled' => ['.ALPHA.-TOCOPHEROL', '.Alpha.-Tocopherol'],
            'paren titled' => ['(RE) Setting Powder', '(Re) Setting Powder'],
            'internal dash ok' => ['Foo - Bar', 'Foo - Bar'],
            'collapse whitespace' => ['  -  Acme   Pharma  ', 'Acme Pharma'],
            'lowercase' => ['mckesson corporation', 'Mckesson Corporation'],
        ];
    }
}
