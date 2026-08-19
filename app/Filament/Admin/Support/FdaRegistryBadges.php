<?php

namespace App\Filament\Admin\Support;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaWddLicense;
use App\Support\Fda\FdaRegistryStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;

final class FdaRegistryBadges
{
    public static function identifierColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->searchable()
            ->copyable()
            ->fontFamily(FontFamily::Mono);
    }

    public static function identifierEntry(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->placeholder('—')
            ->copyable()
            ->fontFamily(FontFamily::Mono);
    }

    public static function partnerTypeColumn(string $name = 'partner_type'): TextColumn
    {
        return TextColumn::make($name)
            ->label('Type')
            ->badge()
            ->formatStateUsing(fn (mixed $state): string => $state instanceof PartnerType
                ? $state->label()
                : (string) ($state ?? '—'))
            ->color(fn (mixed $state): string => match ($state instanceof PartnerType ? $state : null) {
                PartnerType::Manufacturer => 'info',
                PartnerType::Wholesaler => 'warning',
                PartnerType::Logistics3pl => 'primary',
                PartnerType::Pharmacy => 'success',
                default => 'gray',
            });
    }

    public static function partnerTypeEntry(string $name = 'partner_type'): TextEntry
    {
        return TextEntry::make($name)
            ->label('Type')
            ->badge()
            ->placeholder('—')
            ->formatStateUsing(fn (mixed $state): string => $state instanceof PartnerType
                ? $state->label()
                : (string) ($state ?? '—'))
            ->color(fn (mixed $state): string => match ($state instanceof PartnerType ? $state : null) {
                PartnerType::Manufacturer => 'info',
                PartnerType::Wholesaler => 'warning',
                PartnerType::Logistics3pl => 'primary',
                PartnerType::Pharmacy => 'success',
                default => 'gray',
            });
    }

    public static function activeColumn(string $name = 'is_active'): TextColumn
    {
        return TextColumn::make($name)
            ->label('Status')
            ->badge()
            ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
            ->color(fn (?bool $state): string => $state ? 'success' : 'gray');
    }

    public static function activeEntry(string $name = 'is_active'): TextEntry
    {
        return TextEntry::make($name)
            ->label('Status')
            ->badge()
            ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
            ->color(fn (?bool $state): string => $state ? 'success' : 'gray');
    }

    public static function facilityTypeColumn(string $name = 'facility_type'): TextColumn
    {
        return TextColumn::make($name)
            ->label('Facility type')
            ->badge()
            ->formatStateUsing(fn (mixed $state): string => $state instanceof FacilityType
                ? $state->label()
                : (string) ($state ?? '—'));
    }

    public static function establishmentColumn(): TextColumn
    {
        return TextColumn::make('registration_status')
            ->label('Registration')
            ->badge()
            ->state(fn (FdaEstablishment $record): string => FdaRegistryStatus::establishment($record))
            ->formatStateUsing(fn (string $state): string => match ($state) {
                FdaRegistryStatus::ESTABLISHMENT_REGISTERED => 'Registered',
                FdaRegistryStatus::ESTABLISHMENT_EXPIRED => 'Expired',
                FdaRegistryStatus::ESTABLISHMENT_EXCLUDED => 'Excluded',
                default => $state,
            })
            ->color(fn (string $state): string => match ($state) {
                FdaRegistryStatus::ESTABLISHMENT_REGISTERED => 'success',
                FdaRegistryStatus::ESTABLISHMENT_EXPIRED => 'warning',
                FdaRegistryStatus::ESTABLISHMENT_EXCLUDED => 'danger',
                default => 'gray',
            });
    }

    public static function establishmentEntry(): TextEntry
    {
        return TextEntry::make('registration_status')
            ->label('Registration')
            ->badge()
            ->state(fn (FdaEstablishment $record): string => FdaRegistryStatus::establishment($record))
            ->formatStateUsing(fn (string $state): string => match ($state) {
                FdaRegistryStatus::ESTABLISHMENT_REGISTERED => 'Registered',
                FdaRegistryStatus::ESTABLISHMENT_EXPIRED => 'Expired',
                FdaRegistryStatus::ESTABLISHMENT_EXCLUDED => 'Excluded',
                default => $state,
            })
            ->color(fn (string $state): string => match ($state) {
                FdaRegistryStatus::ESTABLISHMENT_REGISTERED => 'success',
                FdaRegistryStatus::ESTABLISHMENT_EXPIRED => 'warning',
                FdaRegistryStatus::ESTABLISHMENT_EXCLUDED => 'danger',
                default => 'gray',
            });
    }

    public static function licenseColumn(): TextColumn
    {
        return TextColumn::make('listing_status')
            ->label('License')
            ->badge()
            ->state(fn (FdaWddLicense $record): string => FdaRegistryStatus::license($record))
            ->formatStateUsing(fn (string $state): string => match ($state) {
                FdaRegistryStatus::LICENSE_ACTIVE => 'Active',
                FdaRegistryStatus::LICENSE_EXPIRED => 'Expired',
                FdaRegistryStatus::LICENSE_DELISTED => 'Delisted',
                default => $state,
            })
            ->color(fn (string $state): string => match ($state) {
                FdaRegistryStatus::LICENSE_ACTIVE => 'success',
                FdaRegistryStatus::LICENSE_EXPIRED => 'warning',
                FdaRegistryStatus::LICENSE_DELISTED => 'gray',
                default => 'gray',
            });
    }

    public static function licenseEntry(): TextEntry
    {
        return TextEntry::make('listing_status')
            ->label('License')
            ->badge()
            ->state(fn (FdaWddLicense $record): string => FdaRegistryStatus::license($record))
            ->formatStateUsing(fn (string $state): string => match ($state) {
                FdaRegistryStatus::LICENSE_ACTIVE => 'Active',
                FdaRegistryStatus::LICENSE_EXPIRED => 'Expired',
                FdaRegistryStatus::LICENSE_DELISTED => 'Delisted',
                default => $state,
            })
            ->color(fn (string $state): string => match ($state) {
                FdaRegistryStatus::LICENSE_ACTIVE => 'success',
                FdaRegistryStatus::LICENSE_EXPIRED => 'warning',
                FdaRegistryStatus::LICENSE_DELISTED => 'gray',
                default => 'gray',
            });
    }

    public static function productKindColumn(): TextColumn
    {
        return TextColumn::make('product_kind')
            ->label('Rx / OTC')
            ->badge()
            ->state(fn (FdaProduct $record): ?string => FdaRegistryStatus::productKind($record))
            ->formatStateUsing(fn (?string $state): string => match ($state) {
                FdaRegistryStatus::PRODUCT_RX => 'Rx',
                FdaRegistryStatus::PRODUCT_OTC => 'OTC',
                default => '—',
            })
            ->color(fn (?string $state): string => match ($state) {
                FdaRegistryStatus::PRODUCT_RX => 'info',
                FdaRegistryStatus::PRODUCT_OTC => 'success',
                default => 'gray',
            });
    }

    public static function deaColumn(): TextColumn
    {
        return TextColumn::make('dea_schedule')
            ->label('DEA')
            ->badge()
            ->formatStateUsing(fn (?string $state): string => FdaRegistryStatus::deaScheduleLabel($state) ?? '—')
            ->color(fn (?string $state): string => FdaRegistryStatus::deaScheduleLabel($state) !== null
                ? 'danger'
                : 'gray');
    }

    public static function reviewStatusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->badge()
            ->formatStateUsing(fn (?string $state): string => match ($state) {
                FdaOrganizationMatchReview::STATUS_PENDING => 'Pending',
                FdaOrganizationMatchReview::STATUS_LINKED => 'Linked',
                FdaOrganizationMatchReview::STATUS_REJECTED => 'Rejected',
                FdaOrganizationMatchReview::STATUS_CREATED_NEW => 'Created New',
                default => (string) ($state ?? '—'),
            })
            ->color(fn (?string $state): string => match ($state) {
                FdaOrganizationMatchReview::STATUS_PENDING => 'warning',
                FdaOrganizationMatchReview::STATUS_LINKED => 'success',
                FdaOrganizationMatchReview::STATUS_CREATED_NEW => 'info',
                FdaOrganizationMatchReview::STATUS_REJECTED => 'gray',
                default => 'gray',
            });
    }

    public static function importOutcomeColumn(): TextColumn
    {
        return TextColumn::make('outcome')
            ->label('Status')
            ->badge()
            ->state(fn (FdaImportRun $record): string => FdaRegistryStatus::importRun($record))
            ->formatStateUsing(fn (string $state): string => match ($state) {
                FdaRegistryStatus::IMPORT_SUCCESS => 'Success',
                FdaRegistryStatus::IMPORT_PARTIAL => 'Partial',
                FdaRegistryStatus::IMPORT_FAILED => 'Failed',
                default => $state,
            })
            ->color(fn (string $state): string => match ($state) {
                FdaRegistryStatus::IMPORT_SUCCESS => 'success',
                FdaRegistryStatus::IMPORT_PARTIAL => 'warning',
                FdaRegistryStatus::IMPORT_FAILED => 'danger',
                default => 'gray',
            });
    }
}
