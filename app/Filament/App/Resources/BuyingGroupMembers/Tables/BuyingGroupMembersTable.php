<?php

namespace App\Filament\App\Resources\BuyingGroupMembers\Tables;

use App\Enums\BuyingGroupMemberStatus;
use App\Filament\App\Resources\BuyingGroupMembers\Actions\BuyingGroupMembershipActions;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\BuyingGroupMember;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class BuyingGroupMembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(BuyingGroupMember::class, 'name')),
                    ),
                TextColumn::make('external_ref')
                    ->label('External ref')
                    ->toggleable()
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('external_ref')
                            ->options(fn (): array => DistinctColumnOptions::of(BuyingGroupMember::class, 'external_ref')),
                    ),
                TextColumn::make('dea_number')
                    ->label('DEA')
                    ->toggleable()
                    ->searchable()
                    ->fontFamily(FontFamily::Mono)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('dea_number')
                            ->options(fn (): array => DistinctColumnOptions::of(BuyingGroupMember::class, 'dea_number')),
                    ),
                TextColumn::make('npi')
                    ->label('NPI')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable()
                    ->fontFamily(FontFamily::Mono)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('npi')
                            ->options(fn (): array => DistinctColumnOptions::of(BuyingGroupMember::class, 'npi')),
                    ),
                TextColumn::make('primary_gln')
                    ->label('Primary GLN')
                    ->toggleable()
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('primary_gln')
                            ->options(fn (): array => DistinctColumnOptions::of(BuyingGroupMember::class, 'primary_gln')),
                    ),
                TextColumn::make('affiliation_code')
                    ->label('Affiliation')
                    ->toggleable()
                    ->searchable()
                    ->columnFilter(ColumnFilter::select()->syncWith('affiliation_code')),
                TextColumn::make('program_sku')
                    ->label('Program SKU')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable()
                    ->columnFilter(ColumnFilter::select()->syncWith('program_sku')),
                TextColumn::make('sites_count')
                    ->label('Sites')
                    ->numeric()
                    ->sortable()
                    ->toggleable()
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('member_tenant_id')
                    ->label('Tenant ID')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('member_tenant_id')
                            ->options(fn (): array => DistinctColumnOptions::of(BuyingGroupMember::class, 'member_tenant_id')),
                    ),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof BuyingGroupMemberStatus
                        ? $state->label()
                        : (string) $state)
                    ->columnFilter(ColumnFilter::select()->syncWith('status')),
                TextColumn::make('contact_email')
                    ->label('Contact')
                    ->searchable()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('contact_email')
                            ->options(fn (): array => DistinctColumnOptions::of(BuyingGroupMember::class, 'contact_email')),
                    ),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No members yet')
            ->emptyStateDescription('Member roster and ATP readiness views only — no member health scores or compliance APIs here.')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(BuyingGroupMemberStatus::cases())->mapWithKeys(
                        fn (BuyingGroupMemberStatus $status): array => [$status->value => $status->label()]
                    )),
                SelectFilter::make('affiliation_code')
                    ->label('Affiliation')
                    ->options(fn (): array => BuyingGroupMember::query()
                        ->whereNotNull('affiliation_code')
                        ->where('affiliation_code', '!=', '')
                        ->distinct()
                        ->orderBy('affiliation_code')
                        ->pluck('affiliation_code', 'affiliation_code')
                        ->all()),
                SelectFilter::make('program_sku')
                    ->label('Program SKU')
                    ->options(fn (): array => BuyingGroupMember::query()
                        ->whereNotNull('program_sku')
                        ->where('program_sku', '!=', '')
                        ->distinct()
                        ->orderBy('program_sku')
                        ->pluck('program_sku', 'program_sku')
                        ->all()),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
                BuyingGroupMembershipActions::invite(),
                BuyingGroupMembershipActions::revoke(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    RegulatoryCompliance::apply(
                        DeleteBulkAction::make(),
                        'buying_group_members_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }
}
