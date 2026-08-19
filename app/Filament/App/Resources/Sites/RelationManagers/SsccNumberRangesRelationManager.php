<?php

namespace App\Filament\App\Resources\Sites\RelationManagers;

use App\Enums\SsccNumberRangeStatus;
use App\Filament\App\Pages\OrganizationSettings;
use App\Filament\App\Resources\SsccNumberRanges\SsccNumberRangeResource;
use App\Models\Site;
use App\Models\SsccNumberRange;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SsccNumberRangesRelationManager extends RelationManager
{
    protected static string $relationship = 'ssccNumberRanges';

    protected static ?string $title = 'SSCC Number Ranges';

    protected static bool $isBadgeDeferred = true;

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! TenantFeatures::forTenant(tenant())->supportsSsccLabeling()
            || ! SsccNumberRangeResource::canAccess()
        ) {
            return false;
        }

        /** @var Site $ownerRecord */
        return EligibleReceiveSites::isEligible($ownerRecord);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Site $ownerRecord */
        $count = $ownerRecord->ssccNumberRanges()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Site-scoped SSCC serial ranges used when minting labels for this facility. Company prefix and extension digit defaults come from Organization settings.')
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company_prefix')
                    ->label('Prefix')
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('extension_digit')
                    ->label('Ext')
                    ->alignCenter(),
                TextColumn::make('index')
                    ->sortable(),
                TextColumn::make('remaining')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?SsccNumberRangeStatus $state): ?string => $state?->label())
                    ->color(fn (?SsccNumberRangeStatus $state): string => $state?->badgeColor() ?? 'gray')
                    ->sortable(),
            ])
            ->defaultSort('index')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No SSCC number ranges for this site')
            ->emptyStateDescription('Create a site-scoped range so pallet labels issued here draw from a dedicated serial band.')
            ->emptyStateActions([
                Action::make('createRange')
                    ->label('New number range')
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => $this->createRangeUrl()),
            ])
            ->headerActions([
                Action::make('createRange')
                    ->label('New number range')
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => $this->createRangeUrl()),
                Action::make('organizationSettings')
                    ->label('Organization settings')
                    ->icon('heroicon-o-building-office')
                    ->color('gray')
                    ->url(fn (): string => OrganizationSettings::getUrl(panel: 'app')),
            ])
            ->recordUrl(fn (SsccNumberRange $record): string => SsccNumberRangeResource::getUrl(
                'edit',
                ['record' => $record],
                panel: 'app',
            ));
    }

    private function createRangeUrl(): string
    {
        /** @var Site $site */
        $site = $this->getOwnerRecord();

        return SsccNumberRangeResource::getUrl('create', panel: 'app').'?'.http_build_query([
            'scope' => 'site',
            'site_id' => $site->getKey(),
        ]);
    }
}
