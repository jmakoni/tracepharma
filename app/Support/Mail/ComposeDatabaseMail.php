<?php

namespace App\Support\Mail;

use App\Models\MailTemplate;
use App\Support\Filament\ProseContent;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

class ComposeDatabaseMail
{
    public function __construct(
        private readonly MailTemplateRenderer $renderer,
    ) {}

    public function shouldSend(string $key): bool
    {
        $row = $this->row($key);

        if ($row instanceof MailTemplate && ! $row->is_active) {
            Log::info('Skipping inactive mail template.', ['key' => $key]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array{address: string, name: string}|null  $from
     */
    public function mailMessage(string $key, array $variables, ?array $from = null): MailMessage
    {
        $definition = MailTemplateCatalog::get($key);
        $row = $this->row($key);

        $subject = $this->renderer->render($row?->subject ?? $definition->defaultSubject, $variables);
        $greeting = $this->renderer->render($row?->greeting ?? $definition->defaultGreeting, $variables);
        $body = $this->renderBody($row?->body ?? $definition->defaultBody, $variables);
        $salutation = $this->renderNullable($row?->salutation ?? $definition->defaultSalutation, $variables);
        $actionLabel = $this->renderNullable($row?->action_label ?? $definition->defaultActionLabel, $variables);
        $actionUrl = $this->renderNullable($row?->action_url ?? $definition->defaultActionUrl, $variables);

        $mail = (new MailMessage)->subject($subject);

        if ($from !== null) {
            $mail->from($from['address'], $from['name']);
        }

        if ($greeting !== '') {
            $mail->greeting($greeting);
        }

        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            if ($line !== '') {
                $mail->line($line);
            }
        }

        if ($actionLabel !== null && $actionLabel !== '' && $actionUrl !== null && $actionUrl !== '') {
            $mail->action($actionLabel, $actionUrl);
        }

        if ($salutation !== null && $salutation !== '') {
            $mail->salutation($salutation);
        }

        return $mail;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function preview(string $key, ?array $variables = null): MailMessage
    {
        $definition = MailTemplateCatalog::get($key);

        return $this->mailMessage($key, $variables ?? $definition->fixtures);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function previewPlainText(string $key, ?array $variables = null): string
    {
        $mail = $this->preview($key, $variables);
        $parts = array_filter([
            'Subject: '.$mail->subject,
            $mail->greeting,
            ...$mail->introLines,
            filled($mail->actionText) && filled($mail->actionUrl)
                ? $mail->actionText.' ('.$mail->actionUrl.')'
                : null,
            $mail->salutation,
        ], static fn (mixed $line): bool => is_string($line) && $line !== '');

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    private function renderBody(string $template, array $variables): string
    {
        if (ProseContent::isTipTapDocument($template)) {
            $mergeTags = [];

            foreach ($variables as $key => $value) {
                // Values are plain text in TipTap nodes; Markdown/mail layers handle escaping.
                $mergeTags[$key] = $value === null ? '' : (string) $value;
            }

            return ProseContent::tipTapToMarkdownWithMergeTags($template, $mergeTags);
        }

        return $this->renderer->render($template, $variables);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    private function row(string $key): ?MailTemplate
    {
        try {
            return MailTemplate::query()->where('key', $key)->first();
        } catch (QueryException) {
            return null;
        }
    }

    private function renderNullable(?string $template, array $variables): ?string
    {
        if ($template === null || $template === '') {
            return $template;
        }

        return $this->renderer->render($template, $variables);
    }
}
