<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\Schemas;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Models\Fda\FdaOrganization;
use App\Support\Fda\FdaRegistryStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaOrganizationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->placeholder('—')
                        ->state(fn (FdaOrganization $record): ?string => $record->name ?: $record->original_name),
                    TextEntry::make('canonical_name')->placeholder('—'),
                    TextEntry::make('original_name')->placeholder('—'),
                    TextEntry::make('doing_business_as')->placeholder('—'),
                    FdaRegistryBadges::partnerTypeEntry(),
                    FdaRegistryBadges::activeEntry(),
                    FdaRegistryBadges::identifierEntry('gln', 'GLN'),
                    FdaRegistryBadges::identifierEntry('duns_number', 'DUNS'),
                ]),
            Section::make('Authorization')
                ->schema([
                    TextEntry::make('authorization')
                        ->hiddenLabel()
                        ->state(fn (FdaOrganization $record): string => FdaRegistryStatus::organizationAuthorization($record))
                        ->columnSpanFull(),
                ]),
            Section::make('Contact')
                ->columns(2)
                ->schema([
                    TextEntry::make('telephone')->placeholder('—'),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('fax')->placeholder('—'),
                    TextEntry::make('website')->placeholder('—'),
                ]),
            Section::make('Address')
                ->columns(2)
                ->schema([
                    TextEntry::make('street_address')->placeholder('—'),
                    TextEntry::make('street_address_2')->placeholder('—'),
                    TextEntry::make('city')->placeholder('—'),
                    TextEntry::make('state_province')->label('State / province')->placeholder('—'),
                    TextEntry::make('postal_code')->placeholder('—'),
                    TextEntry::make('country_code')->placeholder('—'),
                    TextEntry::make('full_address')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
