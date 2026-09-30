<?php

namespace Tests\Unit\Support\Tables;

use App\Enums\PartnerType;
use App\Support\Tables\DistinctColumnOptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DistinctColumnOptionsTest extends TestCase
{
    #[Test]
    public function boolean_options_use_stable_keys(): void
    {
        $this->assertSame(
            ['1' => 'Active', '0' => 'Inactive'],
            DistinctColumnOptions::boolean('Active', 'Inactive'),
        );
    }

    #[Test]
    public function enum_options_use_labels(): void
    {
        $options = DistinctColumnOptions::enum(PartnerType::class);

        $this->assertArrayHasKey(PartnerType::Wholesaler->value, $options);
        $this->assertSame(PartnerType::Wholesaler->label(), $options[PartnerType::Wholesaler->value]);
    }
}
