<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Filament;

use App\Support\Filament\OptionalFilamentPlugins;
use Filament\Panel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OptionalFilamentPluginsTest extends TestCase
{
    #[Test]
    public function register_skips_plugin_when_class_is_missing(): void
    {
        $panel = Panel::make();

        $result = OptionalFilamentPlugins::register(
            $panel,
            'App\\Support\\Filament\\DefinitelyMissingPlugin',
            fn () => new \stdClass,
        );

        $this->assertSame($panel, $result);
    }
}
