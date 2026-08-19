<?php

namespace Tests\Unit\Support\Ui;

use App\Support\Ui\CopyableIdentifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CopyableIdentifierTest extends TestCase
{
    #[Test]
    public function outline_button_is_hover_revealed_bordered_control(): void
    {
        $html = CopyableIdentifier::outlineButtonHtml('003011610011081850')?->toHtml();

        $this->assertNotNull($html);
        $this->assertStringContainsString('navigator.clipboard.writeText', $html);
        $this->assertStringContainsString('003011610011081850', $html);
        $this->assertStringContainsString('title="Copy"', $html);
        $this->assertStringContainsString('tp-copy-btn', $html);
        $this->assertStringContainsString('opacity-0', $html);
        $this->assertStringContainsString('group-hover:opacity-100', $html);
        $this->assertStringNotContainsString('border-', $html);
    }

    #[Test]
    public function outline_button_null_for_blank_or_placeholder(): void
    {
        $this->assertNull(CopyableIdentifier::outlineButtonHtml(null));
        $this->assertNull(CopyableIdentifier::outlineButtonHtml(''));
        $this->assertNull(CopyableIdentifier::outlineButtonHtml('—'));
    }
}
