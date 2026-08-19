<?php

namespace App\Filament\App\Resources\Sites\Pages;

use App\Filament\App\Resources\Sites\RelationManagers\AtpLicensesRelationManager;
use App\Filament\App\Resources\Sites\Schemas\SiteInfolist;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Site;
use App\Support\Catalog\SiteDisplayTitle;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewSite extends ViewRecord
{
    protected static string $resource = SiteResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing('tradingPartner');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Site $record */
        $record = $this->getRecord();

        return SiteDisplayTitle::make($record) ?: $this->getRecordTitle();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Site compliance and scan locations.';
    }

    public function infolist(Schema $schema): Schema
    {
        return SiteInfolist::configure($schema, compact: $this->shouldUseCompactSiteProfile());
    }

    protected function shouldUseCompactSiteProfile(): bool
    {
        return $this->activeRelationManager === (string) array_search(
            AtpLicensesRelationManager::class,
            SiteResource::getRelations(),
            true,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                EditAction::make()
                    ->label('Edit site')
                    ->color('primary')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modal()
                    ->modalWidth(Width::FiveExtraLarge)
                    ->mutateFormDataUsing(fn (array $data): array => Site::syncOrganizationFacilityFlag($data)),
                'sites_edit',
                requireReason: false,
            ),
        ];
    }
}
