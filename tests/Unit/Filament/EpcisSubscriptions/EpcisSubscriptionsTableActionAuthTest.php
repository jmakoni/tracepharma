<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\EpcisSubscriptions;

use App\Filament\App\Resources\EpcisSubscriptions\Tables\EpcisSubscriptionsTable;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class EpcisSubscriptionsTableActionAuthTest extends TestCase
{
    #[Test]
    public function rotate_secret_and_test_ping_authorize_update(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(EpcisSubscriptionsTable::class))->getFileName() ?: '',
        );

        $this->assertNotFalse($source);
        $this->assertSame(2, substr_count((string) $source, "->authorize('update')"));
        $this->assertStringContainsString("Action::make('rotateSecret')", (string) $source);
        $this->assertStringContainsString("Action::make('testPing')", (string) $source);
    }
}
