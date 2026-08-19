<?php

namespace App\Filament\Admin\Resources\MailTemplates\Schemas;

use App\Models\MailTemplate;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MailTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Catalog')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    Placeholder::make('catalog_label')
                        ->label('Template')
                        ->content(fn (?MailTemplate $record): string => $record?->definition()->label ?? '—'),
                    Placeholder::make('catalog_key')
                        ->label('Key')
                        ->content(fn (?MailTemplate $record): string => $record?->key ?? '—'),
                    Placeholder::make('available_variables')
                        ->label('Available variables')
                        ->content(fn (?MailTemplate $record): string => self::variableList($record))
                        ->columnSpanFull(),
                    Placeholder::make('recipients_display')
                        ->label('Recipients')
                        ->content(fn (?MailTemplate $record): string => implode(', ', $record?->recipients ?? $record?->definition()->recipients ?? []) ?: '—'),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->helperText('Inactive templates are skipped. The form submission still succeeds.')
                        ->inline(false),
                ]),
            Section::make('Copy')
                ->compact()
                ->schema([
                    TextInput::make('subject')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Use {{ variable_name }} merge tags only. Blade is not rendered.'),
                    TextInput::make('greeting')
                        ->maxLength(255),
                    Textarea::make('body')
                        ->required()
                        ->rows(12)
                        ->columnSpanFull()
                        ->helperText('One paragraph per line. Markdown **bold** is allowed. Merge tags are HTML-escaped.'),
                    TextInput::make('salutation')
                        ->maxLength(255),
                    TextInput::make('action_label')
                        ->maxLength(255),
                    TextInput::make('action_url')
                        ->maxLength(255)
                        ->helperText('Both label and URL must be non-empty after merge to show a button.'),
                ]),
        ]);
    }

    private static function variableList(?MailTemplate $record): string
    {
        if (! $record instanceof MailTemplate) {
            return '—';
        }

        $tags = array_map(
            static fn (string $variable): string => '{{ '.$variable.' }}',
            $record->definition()->variables,
        );

        return $tags === [] ? '—' : implode(', ', $tags);
    }
}
