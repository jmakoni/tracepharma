<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\Schemas;

use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Models\Fda\FdaEstablishment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaEstablishmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->columns(2)
                ->schema([
                    FdaRegistryBadges::identifierEntry('fei_number', 'FEI'),
                    TextEntry::make('name')->placeholder('—'),
                    TextEntry::make('firm_name')->placeholder('—'),
                    FdaRegistryBadges::identifierEntry('gln', 'GLN'),
                    FdaRegistryBadges::identifierEntry('duns_number', 'DUNS'),
                    FdaRegistryBadges::establishmentEntry(),
                    FdaRegistryBadges::activeEntry(),
                    TextEntry::make('organization.name')
                        ->label('Organization')
                        ->url(fn (FdaEstablishment $record): ?string => $record->fda_organization_id
                            ? FdaOrganizationResource::getUrl('view', ['record' => $record->fda_organization_id])
                            : null),
                    TextEntry::make('expiration_date')->date()->placeholder('—'),
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
            Section::make('Contacts')
                ->columns(2)
                ->schema([
                    TextEntry::make('establishment_contact_name')->placeholder('—'),
                    TextEntry::make('establishment_contact_email')->placeholder('—'),
                    TextEntry::make('registrant_contact_name')->placeholder('—'),
                    TextEntry::make('registrant_contact_email')->placeholder('—'),
                    TextEntry::make('agent_details')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
