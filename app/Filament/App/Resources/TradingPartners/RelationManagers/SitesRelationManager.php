<?php

namespace App\Filament\App\Resources\TradingPartners\RelationManagers;

use App\Actions\MasterData\CopyFdaWddLicensesToTenantSite;
use App\Enums\SiteAtpReadinessStatus;
use App\Filament\App\Resources\Sites\Schemas\SiteForm;
use App\Filament\App\Resources\Sites\Tables\SitesTable;
use App\Filament\App\Support\FdaPicker;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Rules\RejectPartnerGlnUnderOrgPrefix;
use App\Rules\RejectTenantGln;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaTenantLink;
use App\Support\Gs1\GlnRules;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\MasterData\PartnerSiteCreate;
use App\Support\MasterData\SiteAtpReadiness;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    protected static ?string $title = 'Sites';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->manualSiteFormComponents());
    }

    public function table(Table $table): Table
    {
        return $table
            // atpLicenses: the ATP readiness column is summarized per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('atpLicenses'))
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
                TextColumn::make('code')->searchable()->toggleable(),
                TextColumn::make('atp_readiness')
                    ->label('ATP readiness')
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
                TernaryFilter::make('is_active')->default(true),
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
            ->headerActions([
                $this->createSiteAction(),
            ])
            ->recordActions(RecordActionGroup::make([
                SitesTable::viewAction(),
                RegulatoryCompliance::apply(
                    EditAction::make()
                        ->modal()
                        ->modalWidth(Width::FiveExtraLarge)
                        ->schema(fn (Schema $schema): array => SiteForm::configure($schema)->getComponents())
                        ->mutateDataUsing(function (array $data): array {
                            $data['trading_partner_id'] = $this->getOwnerRecord()->getKey();

                            return Site::syncOrganizationFacilityFlag($data);
                        }),
                    'trading_partner_sites_edit',
                    requireReason: false,
                ),
                RegulatoryCompliance::apply(
                    SitesTable::deleteAction(),
                    'trading_partner_sites_delete',
                    requireReason: true,
                ),
            ]));
    }

    private function createSiteAction(): CreateAction
    {
        return RegulatoryCompliance::apply(
            CreateAction::make()
                ->label('New site')
                ->modalHeading('New site')
                ->modalWidth(Width::FiveExtraLarge)
                ->modalSubmitActionLabel('Create site')
                ->createAnother(false)
                ->schema(fn (): array => $this->createSiteFormComponents())
                ->mutateDataUsing(function (array $data): array {
                    /** @var TradingPartner $partner */
                    $partner = $this->getOwnerRecord();

                    return PartnerSiteCreate::resolveCreateData($partner, $data);
                })
                ->after(function (Model $record): void {
                    if (! $record instanceof Site) {
                        return;
                    }

                    if (FdaTenantLink::wddFacilityId($record) === null) {
                        return;
                    }

                    app(CopyFdaWddLicensesToTenantSite::class)->handle($record);
                }),
            'trading_partner_sites_create',
            requireReason: false,
        );
    }

    /**
     * @return array<int, Radio|Select|Section>
     */
    private function createSiteFormComponents(): array
    {
        /** @var TradingPartner $partner */
        $partner = $this->getOwnerRecord();
        $organizationId = FdaTenantLink::organizationId($partner);

        return [
            Radio::make('create_mode')
                ->label('How would you like to add this site?')
                ->options([
                    PartnerSiteCreate::MODE_FDA => 'From FDA registry',
                    PartnerSiteCreate::MODE_MANUAL => 'Create manually',
                ])
                ->default(PartnerSiteCreate::defaultCreateMode($partner))
                ->disableOptionWhen(
                    fn (string $value): bool => $value === PartnerSiteCreate::MODE_FDA
                        && ! PartnerSiteCreate::hasFdaLink($partner),
                )
                ->helperText(function () use ($partner): ?string {
                    if (PartnerSiteCreate::hasFdaLink($partner)) {
                        return 'Pick an FDA establishment or WDD facility that belongs to this partner.';
                    }

                    return 'Link this partner to an FDA organization to pick a registry site.';
                })
                ->live()
                ->inline()
                ->columnSpanFull(),
            Section::make('FDA registry')
                ->compact()
                ->visible(fn (Get $get): bool => $get('create_mode') === PartnerSiteCreate::MODE_FDA)
                ->schema(FdaPicker::partnerLocation($organizationId)),
            Section::make('Identity')
                ->compact()
                ->columns(['md' => 2])
                ->visible(fn (Get $get): bool => in_array($get('create_mode'), [
                    PartnerSiteCreate::MODE_FDA,
                    PartnerSiteCreate::MODE_MANUAL,
                ], true))
                ->schema([
                    TextInput::make('name')
                        ->required(fn (Get $get): bool => in_array($get('create_mode'), [
                            PartnerSiteCreate::MODE_FDA,
                            PartnerSiteCreate::MODE_MANUAL,
                        ], true))
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('code')->unique(ignoreRecord: true)->maxLength(255),
                    GlnRules::input()
                        ->unique(ignoreRecord: true)
                        ->rule(new RejectTenantGln)
                        ->rule(new RejectPartnerGlnUnderOrgPrefix),
                    TextInput::make('duns_number')->label('DUNS')->maxLength(14),
                    TextInput::make('dea_number')->label('DEA')->maxLength(20),
                    TextInput::make('hin_number')->label('HIN')->maxLength(20),
                    TextInput::make('chemical_reg_number')->label('Chemical Reg')->maxLength(30),
                    Toggle::make('is_headquarters')->default(false),
                    Toggle::make('is_active')->default(true),
                ]),
            Section::make('Address')
                ->compact()
                ->columns(['md' => 2])
                ->visible(fn (Get $get): bool => in_array($get('create_mode'), [
                    PartnerSiteCreate::MODE_FDA,
                    PartnerSiteCreate::MODE_MANUAL,
                ], true))
                ->schema([
                    TextInput::make('street_address')->maxLength(255)->columnSpanFull(),
                    TextInput::make('street_address_2')->maxLength(255)->columnSpanFull(),
                    TextInput::make('city')->maxLength(255),
                    TextInput::make('state')->maxLength(100),
                    TextInput::make('zipcode')->maxLength(20),
                    TextInput::make('country_code')->default('US')->maxLength(3),
                    TextInput::make('timezone')->maxLength(64)->placeholder('America/New_York')->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<int, TextInput|Toggle>
     */
    private function manualSiteFormComponents(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->unique(ignoreRecord: true)->maxLength(255),
            GlnRules::input()
                ->unique(ignoreRecord: true)
                ->rule(new RejectTenantGln)
                ->rule(new RejectPartnerGlnUnderOrgPrefix),
            TextInput::make('duns_number')->label('DUNS')->maxLength(14),
            TextInput::make('dea_number')->label('DEA')->maxLength(20),
            TextInput::make('hin_number')->label('HIN')->maxLength(20),
            TextInput::make('chemical_reg_number')->label('Chemical Reg')->maxLength(30),
            Toggle::make('is_headquarters')->default(false),
            TextInput::make('city')->maxLength(255),
            TextInput::make('state')->maxLength(100),
            Toggle::make('is_active')->default(true),
        ];
    }
}
