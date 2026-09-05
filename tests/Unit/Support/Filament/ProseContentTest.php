<?php

namespace Tests\Unit\Support\Filament;

use App\Models\MailTemplate;
use App\Support\Filament\ProseContent;
use App\Support\Mail\ComposeDatabaseMail;
use App\Support\Mail\MailTemplateCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProseContentTest extends TestCase
{
    #[Test]
    public function it_detects_tip_tap_documents_and_converts_them(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Hello world',
                            'marks' => [['type' => 'bold']],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertTrue(ProseContent::isTipTapDocument($doc));
        $this->assertTrue(ProseContent::isTipTapDocument(json_encode($doc)));
        $this->assertFalse(ProseContent::isTipTapDocument("Line one\nLine two"));
        $this->assertStringContainsString('Hello world', ProseContent::toMarkdown($doc));
        $this->assertSame('Hello world', ProseContent::toPlainText($doc));
        $this->assertTrue(ProseContent::isBlank([
            'type' => 'doc',
            'content' => [['type' => 'paragraph']],
        ]));
    }

    #[Test]
    public function legacy_plain_mail_bodies_still_compose(): void
    {
        MailTemplate::syncFromCatalog();

        $mail = app(ComposeDatabaseMail::class)->preview(MailTemplateCatalog::TenantProvisionedOwner);

        $this->assertNotSame('', (string) $mail->subject);
        $this->assertNotEmpty($mail->introLines);
    }

    #[Test]
    public function tip_tap_mail_body_substitutes_merge_tags_and_keeps_markdown(): void
    {
        MailTemplate::syncFromCatalog();

        $template = json_encode([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Welcome {{ tenant_name }}'],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'bold bit',
                            'marks' => [['type' => 'bold']],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $row = MailTemplate::query()
            ->where('key', MailTemplateCatalog::TenantProvisionedOwner)
            ->firstOrFail();

        $original = $row->body;
        $row->forceFill(['body' => $template])->save();

        try {
            $definition = MailTemplateCatalog::get(MailTemplateCatalog::TenantProvisionedOwner);
            $mail = app(ComposeDatabaseMail::class)->mailMessage(
                MailTemplateCatalog::TenantProvisionedOwner,
                $definition->fixtures,
            );

            $joined = implode("\n", $mail->introLines);

            $this->assertStringContainsString((string) $definition->fixtures['tenant_name'], $joined);
            $this->assertStringContainsString('bold bit', $joined);
            $this->assertStringNotContainsString('{{ tenant_name }}', $joined);
        } finally {
            $row->forceFill(['body' => $original])->save();
        }
    }
}
