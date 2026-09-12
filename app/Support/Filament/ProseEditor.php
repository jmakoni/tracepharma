<?php

namespace App\Support\Filament;

use Kisame76\FilamentAdvancedRichEditor\Forms\Components\AdvancedRichEditor;

/**
 * Shared lean AdvancedRichEditor for long-form prose (not technical Textareas).
 */
final class ProseEditor
{
    /**
     * Narrative fields: edit as rich text, store Markdown for mail/search/legacy consumers.
     */
    public static function make(string $name): AdvancedRichEditor
    {
        return self::base($name)
            ->formatStateUsing(fn (mixed $state): mixed => ProseContent::forEditor($state))
            ->dehydrateStateUsing(fn (mixed $state): ?string => ProseContent::toMarkdownOrNull($state));
    }

    /**
     * Mail template body: store TipTap JSON so merge tags remain editor nodes.
     *
     * @param  list<string>|\Closure(): list<string>  $mergeTags
     */
    public static function mailBody(string $name, array|\Closure $mergeTags = []): AdvancedRichEditor
    {
        return self::base($name)
            ->mergeTags($mergeTags)
            ->formatStateUsing(fn (mixed $state): mixed => ProseContent::forEditor($state))
            ->helperText('Use merge tags from the catalog. Formatting is converted to Markdown when the mail is sent.');
    }

    private static function base(string $name): AdvancedRichEditor
    {
        return AdvancedRichEditor::make($name)
            ->toolbarButtons([
                ['undo', 'redo'],
                'divider',
                ['headings', 'bold', 'italic'],
                'divider',
                ['bulletList', 'orderedList', 'link'],
            ])
            ->headingLevels([2, 3])
            ->listTypes(['bulletList', 'orderedList'])
            ->disableToolbarButtons([
                'attachFiles',
                'image',
                'embed',
                'table',
                'codeBlock',
            ])
            ->columnSpanFull();
    }
}
