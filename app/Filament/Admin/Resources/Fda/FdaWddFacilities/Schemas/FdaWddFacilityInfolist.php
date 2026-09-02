<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\Schemas;

use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Models\Fda\FdaWddFacility;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaWddFacilityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->placeholder('—'),
                    TextEntry::make('facility_name')->placeholder('—'),
                    TextEntry::make('alternate_name')->placeholder('—'),
                    TextEntry::make('facility_type')
                        ->label('Facility type')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
                    FdaRegistryBadges::identifierEntry('gln', 'GLN'),
                    FdaRegistryBadges::identifierEntry('sgln', 'SGLN'),
                    FdaRegistryBadges::identifierEntry('duns_number', 'DUNS'),
                    FdaRegistryBadges::identifierEntry('dea_number', 'DEA'),
                    FdaRegistryBadges::identifierEntry('hin_number', 'HIN'),
                    FdaRegistryBadges::identifierEntry('chemical_reg_number', 'Chemical Reg'),
                    FdaRegistryBadges::identifierEntry('code', 'Code'),
                    FdaRegistryBadges::activeEntry(),
                    TextEntry::make('organization.name')
                        ->label('Organization')
                        ->url(fn (FdaWddFacility $record): ?string => $record->fda_organization_id
                            ? FdaOrganizationResource::getUrl('view', ['record' => $record->fda_organization_id])
                            : null),
                    TextEntry::make('contact_person')->placeholder('—'),
                    TextEntry::make('contact_email')->placeholder('—'),
                    TextEntry::make('contact_phone')->label('Contact phone')->placeholder('—'),
                    TextEntry::make('soonest_expiration_date')
                        ->label('Soonest license expiration')
                        ->state(fn (FdaWddFacility $record): ?string => $record->licenses()
                            ->where('is_active', true)
                            ->whereNotNull('expiration_date')
                            ->min('expiration_date'))
                        ->date()
                        ->placeholder('—'),
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
