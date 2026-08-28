<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Compliance;

use App\Support\Compliance\SopLibraryPdf;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SopLibraryPdfTest extends TestCase
{
    #[Test]
    public function render_returns_non_empty_pdf_bytes(): void
    {
        $bytes = app(SopLibraryPdf::class)->render();

        $this->assertNotSame('', $bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }
}
