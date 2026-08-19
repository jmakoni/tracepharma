<?php

declare(strict_types=1);

namespace App\Support\Ui;

use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\IconSize;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\View\ComponentAttributeBag;

use function Filament\Support\generate_icon_html;

/**
 * Outline clipboard control for identifier displays (hover-reveal).
 *
 * Filament's native ->copyable() only makes text clickable (no icon) and
 * conflicts with ->url() Asset Tracking links. Prefer this instead.
 */
final class CopyableIdentifier
{
    public const CELL_CLASS = 'tp-identifier-trace';

    public static function applyToColumn(TextColumn $column): TextColumn
    {
        return $column
            ->suffix(fn (mixed $state): ?HtmlString => self::outlineButtonHtml($state))
            ->extraCellAttributes(function (mixed $state): array {
                return [
                    'class' => self::CELL_CLASS.' group',
                ];
            }, merge: true);
    }

    public static function applyToEntry(TextEntry $entry): TextEntry
    {
        return $entry
            ->suffix(fn (mixed $state): ?HtmlString => self::outlineButtonHtml($state))
            ->extraAttributes(['class' => self::CELL_CLASS.' group'], merge: true);
    }

    public static function outlineButtonHtml(mixed $state, string $title = 'Copy'): ?HtmlString
    {
        if (! self::hasCopyValue($state)) {
            return null;
        }

        $value = (string) $state;
        $js = Js::from($value);
        $icon = generate_icon_html(
            'heroicon-o-clipboard',
            attributes: (new ComponentAttributeBag)->class(['h-3.5', 'w-3.5']),
            size: IconSize::Small,
        )?->toHtml() ?? '';

        return new HtmlString(
            '<button'
            .' type="button"'
            .' title="'.e($title).'"'
            .' class="tp-copy-btn ms-1 inline-flex shrink-0 items-center justify-center bg-transparent p-0.5 text-gray-500 opacity-0 transition-opacity hover:text-gray-700 focus-visible:opacity-100 group-hover:opacity-100 dark:text-gray-400 dark:hover:text-gray-200"'
            .' x-on:click.prevent.stop="window.navigator.clipboard.writeText('.$js.'); $tooltip(\'Copied\', { theme: $store.theme, timeout: 2000 })"'
            .' x-on:keydown.enter.prevent.stop="window.navigator.clipboard.writeText('.$js.'); $tooltip(\'Copied\', { theme: $store.theme, timeout: 2000 })"'
            .'>'.$icon.'</button>'
        );
    }

    /** @deprecated Use outlineButtonHtml() */
    public static function suffixHtml(mixed $state): ?HtmlString
    {
        return self::outlineButtonHtml($state);
    }

    private static function hasCopyValue(mixed $state): bool
    {
        return filled($state) && (string) $state !== '—';
    }
}
