<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Epcis;

use App\Actions\Epcis\ValidateEpcis12Document;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ValidateEpcis12PackingQuantitySourceTest extends TestCase
{
    #[Test]
    public function packing_children_check_reads_event_quantities_child_quantity_list(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(ValidateEpcis12Document::class))->getFileName() ?: '',
        );

        $this->assertNotFalse($source);
        $this->assertStringContainsString('EventQuantity::query()', $source);
        $this->assertStringContainsString('childQuantityList', $source);
        $this->assertStringContainsString('hasChildQuantityList', $source);
    }
}
