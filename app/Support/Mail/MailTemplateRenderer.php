<?php

namespace App\Support\Mail;

class MailTemplateRenderer
{
    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function render(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/',
            function (array $matches) use ($variables): string {
                $key = $matches[1];

                if (! array_key_exists($key, $variables) || $variables[$key] === null) {
                    return '';
                }

                return e((string) $variables[$key]);
            },
            $template,
        );
    }
}
