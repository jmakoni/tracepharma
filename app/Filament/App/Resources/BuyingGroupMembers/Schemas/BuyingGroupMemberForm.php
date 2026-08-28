<?php

namespace App\Filament\App\Resources\BuyingGroupMembers\Schemas;

use App\Enums\BuyingGroupMemberStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BuyingGroupMemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Member')
                    ->compact()
                    ->columns(['md' => 3])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('external_ref')
                            ->label('External reference')
                            ->maxLength(255),
                        TextInput::make('member_tenant_id')
                            ->label('Member tenant ID')
                            ->maxLength(36)
                            ->helperText('Optional linked TracePharma tenant UUID.'),
                        Select::make('status')
                            ->options(collect(BuyingGroupMemberStatus::cases())->mapWithKeys(
                                fn (BuyingGroupMemberStatus $status): array => [$status->value => $status->label()]
                            ))
                            ->default(BuyingGroupMemberStatus::Active->value)
                            ->required()
                            ->native(false),
                        TextInput::make('contact_email')
                            ->label('Contact email')
                            ->email()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
