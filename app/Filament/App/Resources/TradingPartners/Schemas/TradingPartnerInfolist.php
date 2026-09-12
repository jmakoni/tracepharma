<?php

namespace App\Filament\App\Resources\TradingPartners\Schemas;

use App\Models\AtpCredential;
use App\Models\AtpLicense;
use App\Models\TradingPartner;
use App\Support\MasterData\PartnerAtpSiteCoverage;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class TradingPartnerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.infolists.catalog-trading-partner-profile')
                ->columnSpanFull(),
            Section::make('ATP verification')
                ->description('Authorization is per site. Manufacturer plants use FDA DECRS; other sites need a WDD/3PL license for your organization jurisdictions.')
                ->compact()
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('atp_site_coverage')
                        ->label('')
                        ->getStateUsing(fn (TradingPartner $record): array => PartnerAtpSiteCoverage::rows($record)->all())
                        ->table([
                            TableColumn::make('Site'),
                            TableColumn::make('Source'),
                            TableColumn::make('Status'),
                            TableColumn::make('Note'),
                        ])
                        ->schema([
                            TextEntry::make('name')
                                ->label('Site')
                                ->formatStateUsing(function (mixed $state, mixed $record): string {
                                    $name = filled($state) ? (string) $state : 'Site';
                                    $place = is_array($record) ? trim((string) ($record['place'] ?? '')) : '';

                                    return $place !== '' ? $name.' · '.$place : $name;
                                }),
                            TextEntry::make('source_label')
                                ->label('Source')
                                ->placeholder('—'),
                            TextEntry::make('badge_label')
                                ->label('Status')
                                ->badge()
                                ->color(fn (mixed $state, mixed $record): string => is_array($record)
                                    ? (string) ($record['badge_color'] ?? 'gray')
                                    : 'gray'),
                            TextEntry::make('note')
                                ->label('Note')
                                ->placeholder('—'),
                        ])
                        ->placeholder('No sites yet.'),
                    TextEntry::make('atp_status_rollup')
                        ->label('Partner ATP status')
                        ->badge()
                        ->getStateUsing(fn (TradingPartner $record): string => $record->atpStatus())
                        ->formatStateUsing(fn (string $state): string => ucfirst($state))
                        ->color(fn (string $state): string => match ($state) {
                            'verified' => 'success',
                            'expiring', 'pending' => 'warning',
                            'expired' => 'danger',
                            default => 'gray',
                        })
                        ->helperText('Worst-of roll-up across partner-level and site-level licenses.'),
                    TextEntry::make('oci_live_credential_status')
                        ->label('Latest OCI credential (VRS)')
                        ->badge()
                        ->placeholder('None captured')
                        ->getStateUsing(function (TradingPartner $record): ?string {
                            $gln = preg_replace('/\D+/', '', (string) ($record->gln ?? '')) ?: null;
                            if ($gln === null || strlen($gln) !== 13) {
                                return null;
                            }

                            return AtpCredential::query()
                                ->where('subject_gln', $gln)
                                ->latest('id')
                                ->value('verification_status');
                        })
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            AtpCredential::STATUS_VERIFIED => 'Verified (wallet)',
                            AtpCredential::STATUS_SKIPPED => 'Captured (not verified)',
                            AtpCredential::STATUS_EXPIRED => 'Expired',
                            AtpCredential::STATUS_INVALID => 'Invalid',
                            AtpCredential::STATUS_MISSING => 'Missing',
                            AtpCredential::STATUS_ERROR => 'Error',
                            default => $state ? ucfirst($state) : 'None',
                        })
                        ->color(fn (?string $state): string => match ($state) {
                            AtpCredential::STATUS_VERIFIED => 'success',
                            AtpCredential::STATUS_SKIPPED => 'gray',
                            AtpCredential::STATUS_EXPIRED,
                            AtpCredential::STATUS_INVALID,
                            AtpCredential::STATUS_MISSING,
                            AtpCredential::STATUS_ERROR => 'danger',
                            default => 'gray',
                        })
                        ->helperText('Live wallet outcome from inbound VRS ATP headers — not FDA WDD and not manual OCI partner evidence.'),
                    RepeatableEntry::make('partner_licenses')
                        ->label('Partner-level licenses')
                        ->getStateUsing(fn (TradingPartner $record): array => $record->atpLicenses()
                            ->where('is_active', true)
                            ->latest('created_at')
                            ->get()
                            ->map(fn (AtpLicense $license): array => [
                                'license_number' => $license->license_number,
                                'jurisdiction' => trim(($license->license_country ?? '').' '.($license->license_state ?? '')),
                                'expires' => $license->license_expiration_date?->toDateString() ?? '—',
                                'verification' => $license->isPendingVerification() ? 'Pending verification' : 'Verified',
                                'document' => $license->document_original_name ?? '—',
                            ])
                            ->all())
                        ->table([
                            TableColumn::make('License'),
                            TableColumn::make('Jurisdiction'),
                            TableColumn::make('Expires'),
                            TableColumn::make('Verification'),
                            TableColumn::make('Document'),
                        ])
                        ->schema([
                            TextEntry::make('license_number')->label('License'),
                            TextEntry::make('jurisdiction')->label('Jurisdiction')->placeholder('—'),
                            TextEntry::make('expires')->label('Expires'),
                            TextEntry::make('verification')
                                ->label('Verification')
                                ->badge()
                                ->color(fn (string $state): string => $state === 'Verified' ? 'success' : 'warning'),
                            TextEntry::make('document')->label('Document')->placeholder('—'),
                        ])
                        ->placeholder('No partner-level licenses yet — use "Request license update" to collect one.'),
                ]),
        ]);
    }
}
