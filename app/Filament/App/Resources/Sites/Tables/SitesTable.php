<?php

namespace App\Filament\App\Resources\Sites\Tables;

use App\Enums\SiteAtpReadinessStatus;
use App\Filament\App\Resources\Sites\RelationManagers\SsccNumberRangesRelationManager;
use App\Filament\App\Resources\Sites\Schemas\SiteForm;
use App\Filament\App\Resources\Sites\Schemas\SiteSlideOverInfolist;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Site;
use App\Support\Catalog\DisplayName;
use App\Support\MasterData\AtpDisclosure;
use App\Support\MasterData\AtpLicenseExpiry;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\MasterData\SiteAtpReadiness;
use App\Support\MasterData\SiteReferences;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class SitesTable
{
    public static function viewAction(): ViewAction
    {
        return ViewAction::make()
            ->modal()
            ->slideOver()
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->extraModalFooterActions(fn (Site $record): array => [
                Action::make('atpLicensesAndDevices')
                    ->label('ATP licenses & devices')
                    ->color('primary')
                    ->record($record)
                    ->url(fn (Site $record): string => SiteResource::getUrl('view', ['record' => $record], panel: 'app').'?relation=1')
                    ->openUrlInNewTab(false),
                Action::make('ssccNumberRanges')
                    ->label('SSCC number ranges')
                    ->color('gray')
                    ->record($record)
                    ->visible(fn (Site $record): bool => TenantFeatures::forTenant(tenant())->supportsSsccLabeling()
                        && EligibleReceiveSites::isEligible($record))
                    ->url(function (Site $record): string {
                        $relations = SiteResource::getRelations();
                        $index = array_search(
                            SsccNumberRangesRelationManager::class,
                            $relations,
                            true,
                        );

                        return SiteResource::getUrl('view', ['record' => $record], panel: 'app')
                            .'?relation='.(string) (is_int($index) ? $index : 2);
                    })
                    ->openUrlInNewTab(false),
            ])
            ->extraModalWindowAttributes(['class' => 'tp-site-view-slideover'])
            ->mutateRecordDataUsing(function (array $data, Site $record): array {
                $record->loadMissing(['locationDevices', 'atpLicenses']);

                return $data;
            })
            ->registerModalActions([
                RegulatoryCompliance::apply(
                    EditAction::make()
                        ->label('Edit')
                        ->iconButton()
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('gray')
                        ->modal()
                        ->modalWidth(Width::FiveExtraLarge)
                        ->schema(fn (Schema $schema): array => SiteForm::configure($schema)->getComponents())
                        ->mutateFormDataUsing(fn (array $data): array => Site::syncOrganizationFacilityFlag($data)),
                    'sites_edit',
                    requireReason: false,
                ),
                ...SiteSlideOverInfolist::createActions(),
            ])
            ->schema(fn (Schema $schema, ViewAction $action): array => SiteSlideOverInfolist::configure($schema, $action)->getComponents())
            ->modalHeading(function (ViewAction $action): HtmlString {
                $title = e(__('filament-actions::view.single.modal.heading', [
                    'label' => $action->getRecordTitle(),
                ]));
                $edit = $action->getModalAction('edit');

                return new HtmlString(
                    $title.'<span class="tp-site-view-header-edit">'.($edit?->toHtml() ?? '').'</span>',
                );
            });
    }

    /**
     * Hard delete is the last resort: it is blocked while any traceability record still
     * names the location, and the policy hides it from everyone but master-data owners.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Deleting removes the site from master data. It is only possible while no document, session or label batch references it — deactivate instead to keep history intact.')
            ->before(function (DeleteAction $action, Site $record): void {
                $summary = SiteReferences::summary($record);

                if ($summary === null) {
                    return;
                }

                Notification::make()
                    ->title('Site cannot be deleted')
                    ->body('It is referenced by '.$summary.'. Deactivate the site instead.')
                    ->warning()
                    ->persistent()
                    ->send();

                $action->cancel();
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
            // atpLicenses: the ATP readiness column is summarized per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tradingPartner', 'atpLicenses']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('ownership')
                    ->label('Ownership')
                    ->extraHeaderAttributes([
                        'title' => 'Ours = organization facility; receive dropdowns also require active + GLN.',
                    ])
                    ->state(function (Site $record): string {
                        if ($record->is_organization_facility) {
                            return 'Ours';
                        }

                        return DisplayName::clean($record->tradingPartner?->name) ?? 'Partner';
                    }),
                TextColumn::make('receive_eligible')
                    ->label('Receive-eligible')
                    ->badge()
                    ->state(fn (Site $record): string => EligibleReceiveSites::isEligible($record) ? 'Yes' : 'No')
                    ->color(fn (Site $record): string => EligibleReceiveSites::isEligible($record) ? 'success' : 'gray'),
                TextColumn::make('tradingPartner.name')
                    ->label('Partner')
                    ->toggleable()
                    ->placeholder('—')
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
                TextColumn::make('code')->searchable()->toggleable(),
                TextColumn::make('atp_readiness')
                    ->label('ATP readiness')
                    ->extraHeaderAttributes(['title' => AtpDisclosure::SOURCE])
                    ->badge()
                    ->state(fn (Site $record): string => SiteAtpReadiness::badgeLabel($record))
                    ->color(fn (Site $record): string => SiteAtpReadiness::summarize($record)['status']->badgeColor())
                    ->description(fn (Site $record): ?string => SiteAtpReadiness::badgeDescription($record)),
                TextColumn::make('city')
                    ->toggleable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('state')->toggleable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('GLN, code, or site name')
            ->filters([
                SelectFilter::make('ownership')
                    ->options([
                        'ours' => 'Ours',
                        'partners' => 'Partners',
                    ])
                    ->default('ours')
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return match ($value) {
                            'ours' => $query->where('is_organization_facility', true),
                            'partners' => $query->where('is_organization_facility', false),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('is_active')->default(true),
                TernaryFilter::make('receive_eligible')
                    ->label('Receive-eligible')
                    ->trueLabel('Yes')
                    ->falseLabel('No')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn(
                            $query->getModel()->getQualifiedKeyName(),
                            EligibleReceiveSites::forOrganization()->reorder()->select('id'),
                        ),
                        false: fn (Builder $query): Builder => $query->whereNotIn(
                            $query->getModel()->getQualifiedKeyName(),
                            EligibleReceiveSites::forOrganization()->reorder()->select('id'),
                        ),
                    ),
                // Readiness is judged against evaluation jurisdictions (org footprint or
                // preferred receiving state); renewals are due whatever state licensed them.
                Filter::make('atp_expiring_90_days')
                    ->label('License expiring within 90 days')
                    ->toggle()
                    ->default(fn (): bool => request()->query('atp_status') === 'expiring')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'atpLicenses',
                        fn (Builder $licenses): Builder => AtpLicenseExpiry::expiringSoon(
                            $licenses->where('is_active', true),
                        ),
                    )),
                SelectFilter::make('atp_readiness')
                    ->label('ATP readiness')
                    ->options(function (): array {
                        return collect(SiteAtpReadinessStatus::cases())
                            ->reject(fn (SiteAtpReadinessStatus $status): bool => $status === SiteAtpReadinessStatus::NeedsReceivingState
                                && AtpLicenseRelevance::evaluationJurisdictionKeys() !== [])
                            ->mapWithKeys(fn (SiteAtpReadinessStatus $status): array => [
                                $status->value => $status->label(),
                            ])
                            ->all();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return SiteAtpReadiness::applyStatusFilter(
                            $query,
                            SiteAtpReadinessStatus::from((string) $value),
                        );
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                self::viewAction(),
                RegulatoryCompliance::apply(
                    EditAction::make()
                        ->modal()
                        ->modalWidth(Width::FiveExtraLarge)
                        ->mutateFormDataUsing(fn (array $data): array => Site::syncOrganizationFacilityFlag($data)),
                    'sites_edit',
                    requireReason: false,
                ),
                RegulatoryCompliance::apply(
                    self::deleteAction(),
                    'sites_delete',
                    requireReason: true,
                ),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    RegulatoryCompliance::apply(
                        DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                        'sites_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }
}
