<?php

namespace Tests\Unit\Support\Mail;

use App\Support\Mail\MailTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailTemplateRendererTest extends TestCase
{
    #[Test]
    public function it_replaces_allowed_merge_tags(): void
    {
        $rendered = app(MailTemplateRenderer::class)->render(
            'Hi {{ first_name }}, welcome to {{ company_display_name }}.',
            [
                'first_name' => 'Alex',
                'company_display_name' => 'Example Pharmacy',
            ],
        );

        $this->assertSame('Hi Alex, welcome to Example Pharmacy.', $rendered);
    }

    #[Test]
    public function it_escapes_html_in_values(): void
    {
        $rendered = app(MailTemplateRenderer::class)->render(
            'Company: {{ company_display_name }}',
            ['company_display_name' => '<script>alert(1)</script>'],
        );

        $this->assertSame('Company: &lt;script&gt;alert(1)&lt;/script&gt;', $rendered);
        $this->assertStringNotContainsString('<script>', $rendered);
    }

    #[Test]
    public function it_does_not_execute_blade_or_unknown_tags(): void
    {
        $rendered = app(MailTemplateRenderer::class)->render(
            '@php echo "pwned"; @endphp {{ unknown }} {{ first_name }}',
            ['first_name' => 'Alex'],
        );

        $this->assertStringContainsString('@php echo "pwned"; @endphp', $rendered);
        $this->assertStringContainsString('Alex', $rendered);
        $this->assertStringNotContainsString('pwned', str_replace('@php echo "pwned"; @endphp', '', $rendered));
    }
}
