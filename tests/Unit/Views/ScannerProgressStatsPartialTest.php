<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScannerProgressStatsPartialTest extends TestCase
{
    #[Test]
    public function partial_renders_gray_stats_strip_with_titles_and_values(): void
    {
        $html = view('filament.app.partials.scanner-progress-stats', [
            'stats' => [
                ['title' => 'Confirmed', 'value' => 3],
                ['title' => 'Expected', 'value' => 10, 'desc' => 'Residual 7'],
            ],
        ])->render();

        $this->assertStringContainsString('bg-base-200', $html);
        $this->assertStringContainsString('stat-title', $html);
        $this->assertStringContainsString('Confirmed', $html);
        $this->assertStringContainsString('Expected', $html);
        $this->assertStringContainsString('>3</div>', $html);
        $this->assertStringContainsString('>10</div>', $html);
        $this->assertStringContainsString('Residual 7', $html);
        $this->assertStringContainsString('aria-label="Confirmed 3 · Expected 10"', $html);
    }

    #[Test]
    public function partial_renders_nothing_when_stats_empty(): void
    {
        $html = view('filament.app.partials.scanner-progress-stats', [
            'stats' => [],
        ])->render();

        $this->assertSame('', trim($html));
    }
}
