<?php

namespace App\Support\Filament;

use Kisame76\FilamentAdvancedRichEditor\RichEditor\AdvancedRichContentRenderer;
use Kisame76\FilamentAdvancedRichEditor\RichEditor\DocumentContent;

/**
 * Detect TipTap documents vs legacy plain/markdown, and convert for storage, mail, and display.
 */
final class ProseContent
{
    public static function isTipTapDocument(mixed $state): bool
    {
        if (is_array($state)) {
            return ($state['type'] ?? null) === 'doc';
        }

        if (! is_string($state)) {
            return false;
        }

        $trimmed = ltrim($state);

        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return false;
        }

        $decoded = json_decode($state, true);

        return is_array($decoded) && ($decoded['type'] ?? null) === 'doc';
    }

    public static function isBlank(mixed $state): bool
    {
        if ($state === null || $state === '') {
            return true;
        }

        if (self::isTipTapDocument($state)) {
            $document = is_array($state) ? $state : json_decode((string) $state, true);

            return ! is_array($document) || DocumentContent::isBlank($document);
        }

        return trim(strip_tags((string) $state)) === '';
    }

    /**
     * State suitable for AdvancedRichEditor / TipTap (JSON doc, HTML, or empty doc).
     */
    public static function forEditor(mixed $state): mixed
    {
        if ($state === null || $state === '') {
            return [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph'],
                ],
            ];
        }

        if (self::isTipTapDocument($state)) {
            return is_array($state) ? $state : $state;
        }

        $text = (string) $state;

        if (str_contains($text, '<') && str_contains($text, '>')) {
            return $text;
        }

        $paragraphs = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $html = '';

        foreach ($paragraphs as $line) {
            if ($line === '') {
                continue;
            }

            $html .= '<p>'.e($line).'</p>';
        }

        return $html !== '' ? $html : '<p></p>';
    }

    /**
     * Persist as Markdown (readable for emails, activity search, and legacy asserts).
     */
    public static function toMarkdownOrNull(mixed $state): ?string
    {
        if (self::isBlank($state)) {
            return null;
        }

        if (! self::isTipTapDocument($state) && is_string($state) && ! (str_contains($state, '<') && str_contains($state, '>'))) {
            return $state;
        }

        $markdown = trim(AdvancedRichContentRenderer::make($state)->toMarkdown());

        return $markdown === '' ? null : $markdown;
    }

    public static function toMarkdown(mixed $state): string
    {
        return self::toMarkdownOrNull($state) ?? '';
    }

    public static function toPlainText(mixed $state): string
    {
        if ($state === null || $state === '') {
            return '';
        }

        if (self::isTipTapDocument($state)) {
            return trim(AdvancedRichContentRenderer::make($state)->toText());
        }

        return trim(html_entity_decode(strip_tags((string) $state), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function toHtml(mixed $state): string
    {
        if ($state === null || $state === '' || self::isBlank($state)) {
            return '';
        }

        if (self::isTipTapDocument($state)) {
            return AdvancedRichContentRenderer::make($state)->toHtml();
        }

        $text = (string) $state;

        if (str_contains($text, '<') && str_contains($text, '>')) {
            return $text;
        }

        return nl2br(e($text));
    }

    /**
     * @param  array<string, mixed>  $mergeTags
     */
    public static function tipTapToMarkdownWithMergeTags(string|array $document, array $mergeTags): string
    {
        $decoded = is_array($document) ? $document : json_decode($document, true);

        if (! is_array($decoded)) {
            return '';
        }

        // Substitute {{ var }} text nodes before Markdown export — html-to-markdown
        // escapes underscores (`tenant\_name`), which would break MailTemplateRenderer.
        $decoded = self::substitutePlainMergeTagsInDocument($decoded, $mergeTags);

        return trim(AdvancedRichContentRenderer::make($decoded)->mergeTags($mergeTags)->toMarkdown());
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $mergeTags
     * @return array<string, mixed>
     */
    public static function substitutePlainMergeTagsInDocument(array $node, array $mergeTags): array
    {
        if (($node['type'] ?? null) === 'text' && isset($node['text']) && is_string($node['text'])) {
            $node['text'] = (string) preg_replace_callback(
                '/\{\{\s*([a-z0-9_]+)\s*\}\}/',
                static function (array $matches) use ($mergeTags): string {
                    $key = $matches[1];

                    if (! array_key_exists($key, $mergeTags) || $mergeTags[$key] === null) {
                        return '';
                    }

                    return html_entity_decode(strip_tags((string) $mergeTags[$key]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                },
                $node['text'],
            );
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(
                static fn (mixed $child): mixed => is_array($child)
                    ? self::substitutePlainMergeTagsInDocument($child, $mergeTags)
                    : $child,
                $node['content'],
            );
        }

        return $node;
    }
}
