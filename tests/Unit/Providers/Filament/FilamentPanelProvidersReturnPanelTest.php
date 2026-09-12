<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Filament;

use App\Providers\Filament\AdminKnowledgeBasePanelProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\KnowledgeBasePanelProvider;
use Filament\Panel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class FilamentPanelProvidersReturnPanelTest extends TestCase
{
    /**
     * @return list<array{0: class-string}>
     */
    public static function panelProviderClasses(): array
    {
        return [
            [AppPanelProvider::class],
            [AdminPanelProvider::class],
            [KnowledgeBasePanelProvider::class],
            [AdminKnowledgeBasePanelProvider::class],
        ];
    }

    #[Test]
    #[DataProvider('panelProviderClasses')]
    public function panel_method_source_contains_return_panel(string $providerClass): void
    {
        $method = new ReflectionMethod($providerClass, 'panel');
        $file = (string) $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);
        $body = implode('', $lines);

        $this->assertMatchesRegularExpression(
            '/return\s+\$panel\b/',
            $body,
            "{$providerClass}::panel() must return \$panel (incomplete OptionalFilamentPlugins refactor).",
        );
        $this->assertSame(Panel::class, $method->getReturnType()?->getName());
    }
}
