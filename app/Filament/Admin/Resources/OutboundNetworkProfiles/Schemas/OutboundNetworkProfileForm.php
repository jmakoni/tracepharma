<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OutboundNetworkProfiles\Schemas;

use App\Enums\OutboundTransport;
use App\Models\OutboundNetworkProfile;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OutboundNetworkProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Network profile')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('label')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('network_slug')
                        ->label('Network')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('environment')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('default_transport')
                        ->label('Default transport')
                        ->disabled()
                        ->dehydrated(false)
                        ->formatStateUsing(fn (?string $state): string => $state !== null
                            ? (OutboundTransport::tryFrom($state)?->label() ?? $state)
                            : ''),
                    CheckboxList::make('allowed_transports')
                        ->label('Allowed transports')
                        ->options(collect([
                            OutboundTransport::Https,
                            OutboundTransport::Sftp,
                            OutboundTransport::As2,
                        ])->mapWithKeys(
                            fn (OutboundTransport $t): array => [$t->value => $t->label()],
                        )->all())
                        ->columns(3)
                        ->helperText('Email and Client portal are never network transports.')
                        ->disabled(fn (?OutboundNetworkProfile $record): bool => self::isLocked($record)),
                    Toggle::make('is_locked')
                        ->label('Locked (seeded defaults)')
                        ->helperText('Unlock to edit shared values. Re-seeding without --force will not overwrite unlocked rows.')
                        ->columnSpanFull(),
                ]),
            Section::make('Shared network values')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('endpoint_url')
                        ->label('HTTPS endpoint URL')
                        ->url()
                        ->helperText('Query string is part of the URL (Systech/UniTrace Hub).')
                        ->disabled(fn (?OutboundNetworkProfile $record): bool => self::isLocked($record))
                        ->columnSpanFull(),
                    TextInput::make('as2_url')
                        ->label('AS2 URL')
                        ->url()
                        ->helperText('Blank until the network publishes it.')
                        ->disabled(fn (?OutboundNetworkProfile $record): bool => self::isLocked($record))
                        ->columnSpanFull(),
                    TextInput::make('as2_to')
                        ->label('AS2-To')
                        ->disabled(fn (?OutboundNetworkProfile $record): bool => self::isLocked($record)),
                    TextInput::make('as2_subject')
                        ->label('AS2 Subject (optional)')
                        ->disabled(fn (?OutboundNetworkProfile $record): bool => self::isLocked($record)),
                    Textarea::make('notes')
                        ->rows(4)
                        ->helperText('AS2-To presets, SFTP filename patterns, partner hints. AS2-From and secrets stay tenant-owned.')
                        ->disabled(fn (?OutboundNetworkProfile $record): bool => self::isLocked($record))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function isLocked(?OutboundNetworkProfile $record): bool
    {
        return $record?->is_locked ?? true;
    }
}
