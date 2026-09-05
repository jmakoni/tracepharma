<?php

namespace App\Filament\App\Resources\BuyingGroupMembers\Tables;

use App\Enums\BuyingGroupMemberStatus;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
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
                TextColumn::make('member_tenant_id')
                    ->label('Tenant ID')
                    ->toggleable()
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
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(BuyingGroupMemberStatus::cases())->mapWithKeys(
                        fn (BuyingGroupMemberStatus $status): array => [$status->value => $status->label()]
                    )),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
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
