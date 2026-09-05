<?php

namespace App\Filament\App\Resources\TradingPartners\Tables;

use App\Enums\PartnerType;
use App\Filament\App\Resources\TradingPartners\Actions\RecordAtpVerificationAction;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Filament\Support\TradingPartnerModalActions;
use App\Models\TradingPartner;
use App\Services\Quarantine\SupplierPortalService;
use App\Support\Catalog\DisplayName;
use App\Support\MasterData\TradingPartnerReferences;
use App\Support\Scout\TenantModelSearch;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use App\Filament\Notifications\Notification;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use RuntimeException;

class TradingPartnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('street_address')
                    ->label('Street address')
                    ->searchable()
                    ->toggleable()
                    ->wrap()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('gln')
                    ->label('GLN')
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('partner_type')->badge(),
                TextColumn::make('city')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('state')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('portal_share_uuid')
                    ->label('Portal')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? 'Portal active' : 'Not shared')
                    ->color(fn (?string $state): string => $state !== null ? 'success' : 'gray')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('GLN or partner name')
            ->searchUsing(fn (Builder $query, string $search) => TenantModelSearch::constrain(
                $query,
                TradingPartner::class,
                $search,
                ['name', 'doing_business_as', 'gln', 'street_address', 'city', 'state'],
            ))
            ->filters([
                TernaryFilter::make('is_active')->default(true),
                SelectFilter::make('partner_type')
                    ->options(collect(PartnerType::cases())->mapWithKeys(
                        fn (PartnerType $type) => [$type->value => $type->label()]
                    )),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->emptyStateHeading('No trading partners')
            ->emptyStateDescription('Add partners before authorizing products and sites.')
            ->emptyStateActions([
                TradingPartnerModalActions::create(
                    CreateAction::make()->label('Create trading partner'),
                    TradingPartnerResource::class,
                    assignSlug: false,
                ),
            ])
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
                TradingPartnerModalActions::edit(EditAction::make(), lockSlug: false),
                RecordAtpVerificationAction::make(),
                // Confirmation is re-asserted after the gate wrapper, which otherwise makes the
                // modal conditional on the compliance password being enabled.
                RegulatoryCompliance::apply(
                    self::deactivateAction(),
                    'trading_partners_deactivate',
                )->requiresConfirmation(),
                RegulatoryCompliance::apply(
                    self::activateAction(),
                    'trading_partners_activate',
                )->requiresConfirmation(),
                self::copyPortalLinkAction(),
                RegulatoryCompliance::apply(
                    self::rotatePortalLinkAction(),
                    'trading_partners_portal_link_rotate',
                )->requiresConfirmation(),
                RegulatoryCompliance::apply(
                    self::revokePortalLinkAction(),
                    'trading_partners_portal_link_revoke',
                )->requiresConfirmation(),
                RegulatoryCompliance::apply(
                    self::deleteAction(),
                    'trading_partners_delete',
                    requireReason: true,
                ),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    RegulatoryCompliance::apply(
                        self::deactivateBulkAction(),
                        'trading_partners_bulk_deactivate',
                    )->requiresConfirmation(),
                    RegulatoryCompliance::apply(
                        DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                        'trading_partners_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }

    private static function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->authorize('update')
            ->visible(fn (TradingPartner $record): bool => (bool) $record->is_active)
            ->modalHeading('Deactivate trading partner')
            ->modalDescription('The partner keeps naming every historical EPCIS, receiving and shipping record, but stops appearing in new work.')
            ->action(function (TradingPartner $record): void {
                $record->update(['is_active' => false]);

                Notification::make()
                    ->title('Trading partner deactivated')
                    ->body('Ingest will no longer reactivate it.')
                    ->success()
                    ->send();
            });
    }

    private static function activateAction(): Action
    {
        return Action::make('activate')
            ->label('Activate')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->authorize('update')
            ->visible(fn (TradingPartner $record): bool => ! $record->is_active)
            ->modalHeading('Activate trading partner')
            ->action(function (TradingPartner $record): void {
                $record->update(['is_active' => true]);

                Notification::make()
                    ->title('Trading partner activated')
                    ->success()
                    ->send();
            });
    }

    private static function copyPortalLinkAction(): Action
    {
        return Action::make('copyPortalLink')
            ->label('Copy supplier portal link')
            ->icon(Heroicon::OutlinedRectangleStack)
            ->authorize('managePortalLink')
            ->visible(fn (TradingPartner $record): bool => (bool) $record->is_active)
            ->action(function (TradingPartner $record, HasActions & HasSchemas $livewire): void {
                if (! $record->is_active) {
                    Notification::make()
                        ->title('Trading partner is inactive')
                        ->body('Reactivate '.$record->name.' before sharing their exception portal.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $portal = app(SupplierPortalService::class);
                    $url = $portal->signedPartnerExceptionsUrl($record);

                    if (method_exists($livewire, 'js')) {
                        $livewire->js('window.navigator.clipboard.writeText('.Js::from($url).')');
                    }

                    Notification::make()
                        ->title('Supplier portal link copied')
                        ->body('Lists open exceptions for '.$record->name.'. Expires in '.$portal->linkTtlDays().' days.')
                        ->success()
                        ->send();
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('Unable to copy portal link')
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();
                }
            });
    }

    /**
     * Rotating swaps the shared uuid, so links already sent out stop resolving and the
     * partner has to be re-invited with a fresh one.
     */
    private static function rotatePortalLinkAction(): Action
    {
        return Action::make('rotatePortalLink')
            ->label('Rotate supplier portal link')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('managePortalLink')
            ->visible(fn (TradingPartner $record): bool => $record->portal_share_uuid !== null)
            ->modalHeading('Rotate supplier portal link')
            ->modalDescription('Every link already shared with this partner stops working. Copy and send the new link from an exception case afterwards.')
            ->action(function (TradingPartner $record): void {
                app(SupplierPortalService::class)->rotatePartnerPortalLink($record);

                Notification::make()
                    ->title('Supplier portal link rotated')
                    ->body('Previously shared links no longer open the portal.')
                    ->success()
                    ->send();
            });
    }

    private static function revokePortalLinkAction(): Action
    {
        return Action::make('revokePortalLink')
            ->label('Revoke supplier portal link')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('managePortalLink')
            ->visible(fn (TradingPartner $record): bool => $record->portal_share_uuid !== null)
            ->modalHeading('Revoke supplier portal link')
            ->modalDescription('The partner loses access to their open exception cases until a new link is shared.')
            ->action(function (TradingPartner $record): void {
                app(SupplierPortalService::class)->revokePartnerPortalLink($record);

                Notification::make()
                    ->title('Supplier portal link revoked')
                    ->body('The partner can no longer open the exception portal.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Hard delete is the last resort: it is blocked while any traceability record still
     * names the partner, and the policy hides it from everyone but master-data owners.
     */
    private static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Deleting removes the partner from master data. It is only possible while no document, session, exception or connection references it — deactivate instead to keep history intact.')
            ->before(function (DeleteAction $action, TradingPartner $record): void {
                $summary = TradingPartnerReferences::summary($record);

                if ($summary === null) {
                    return;
                }

                Notification::make()
                    ->title('Trading partner cannot be deleted')
                    ->body('It is referenced by '.$summary.'. Deactivate the partner instead.')
                    ->warning()
                    ->persistent()
                    ->send();

                $action->cancel();
            });
    }

    private static function deactivateBulkAction(): BulkAction
    {
        return BulkAction::make('deactivate')
            ->label('Deactivate selected')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->authorize('updateAny')
            ->modalHeading('Deactivate selected trading partners')
            ->action(function (Collection $records): void {
                $deactivated = $records
                    ->filter(fn (TradingPartner $record): bool => (bool) $record->is_active)
                    ->each(fn (TradingPartner $record) => $record->update(['is_active' => false]))
                    ->count();

                Notification::make()
                    ->title($deactivated === 1 ? '1 trading partner deactivated' : $deactivated.' trading partners deactivated')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
