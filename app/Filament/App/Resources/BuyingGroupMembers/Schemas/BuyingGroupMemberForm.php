<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BuyingGroupMembers\Schemas;

use App\Enums\BuyingGroupMemberStatus;
use App\Support\Gs1\GlnRules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
                    ->columns(['md' => 2])
                    ->description('Buying-group roster row — soft identity fields. Hard TracePharma links use Invite / Accept, not free-text tenant IDs.')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('external_ref')
                            ->label('External reference')
                            ->maxLength(255)
                            ->helperText('Optional host / GPO member id.'),
                        TextInput::make('member_tenant_id')
                            ->label('Linked TracePharma tenant')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Set only after the pharmacy Owner accepts a hard-membership invite.'),
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
                        TextInput::make('sites_count')
                            ->label('Sites count')
                            ->numeric()
                            ->minValue(0)
                            ->integer()
                            ->helperText('Optional count of member pharmacy sites.'),
                    ]),
                Section::make('Regulatory identifiers')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('dea_number')
                            ->label('DEA')
                            ->maxLength(20),
                        TextInput::make('npi')
                            ->label('NPI')
                            ->maxLength(20),
                        TextInput::make('state_license_ref')
                            ->label('State license')
                            ->maxLength(255),
                        GlnRules::input('primary_gln', 'Primary GLN')
                            ->nullable()
                            ->helperText('Optional primary location GLN for the member.'),
                    ]),
                Section::make('Program')
                    ->compact()
                    ->columns(['md' => 2])
                    ->collapsed()
                    ->schema([
                        TextInput::make('affiliation_code')
                            ->label('Affiliation code')
                            ->maxLength(255),
                        TextInput::make('program_sku')
                            ->label('Program SKU')
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
