<?php

namespace App\Filament\App\Resources\BuyingGroupMembers\Tables;

use App\Enums\BuyingGroupMemberStatus;
use App\Filament\App\Resources\BuyingGroupMembers\Actions\BuyingGroupMembershipActions;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\BuyingGroupMember;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BuyingGroupMembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('external_ref')
                    ->label('External ref')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('dea_number')
                    ->label('DEA')
                    ->toggleable()
                    ->searchable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('npi')
                    ->label('NPI')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('primary_gln')
                    ->label('Primary GLN')
                    ->toggleable()
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('affiliation_code')
                    ->label('Affiliation')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('program_sku')
                    ->label('Program SKU')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('sites_count')
                    ->label('Sites')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('member_tenant_id')
                    ->label('Tenant ID')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof BuyingGroupMemberStatus
                        ? $state->label()
                        : (string) $state),
                TextColumn::make('contact_email')
                    ->label('Contact')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
