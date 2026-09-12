<?php

namespace App\Filament\Admin\Resources\Fda\FdaImportRuns\Pages;

use App\Filament\Admin\Resources\Fda\FdaImportRuns\FdaImportRunResource;
use App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\FdaWdd3plStagingResource;
use App\Jobs\ImportFdaDatasetJob;
use App\Support\Auth\Permissions;
use App\Support\Fda\FdaImportRunStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListFdaImportRuns extends ListRecords
{
    protected static string $resource = FdaImportRunResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(
            view('filament.admin.fda.import-run-status', [
                'cards' => FdaImportRunStatus::cards(),
            ])->render()
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openWddStaging')
                ->label('Import WDD/3PL')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(FdaWdd3plStagingResource::getUrl('index')),
            $this->queueImportAction(
                'importDecrs',
                'Import DECRS',
                'tracepharma:import-fda-decrs',
                'Queue a background import of FDA establishment registrations (DECRS). Refresh this page after it finishes to see a new import run.',
            ),
            $this->queueImportAction(
                'importOpenFdaNdc',
                'Import NDC',
                'tracepharma:import-openfda-ndc',
                'Queue a background import of the openFDA NDC catalog. This may take several minutes.',
            ),
            $this->queueImportAction(
                'importOpenFdaDrugsFda',
                'Import Drugs@FDA',
                'tracepharma:import-openfda-drugsfda',
                'Queue a background import of Drugs@FDA package NDCs. This may take several minutes.',
            ),
        ];
    }

    private function queueImportAction(string $name, string $label, string $command, string $description): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize(fn (): bool => self::canCurateCatalog())
            ->requiresConfirmation()
            ->modalHeading($label)
            ->modalDescription($description)
            ->schema([
                Toggle::make('fresh_download')
                    ->label('Fresh download')
                    ->helperText('Download a new copy from FDA instead of using the cached file.')
                    ->default(false),
            ])
            ->action(function (array $data) use ($command): void {
                abort_unless(self::canCurateCatalog(), 403);

                $parameters = [];
                if ((bool) ($data['fresh_download'] ?? false)) {
                    $parameters['--fresh-download'] = true;
                }

                if (! ImportFdaDatasetJob::dispatchIfIdle($command, $parameters)) {
                    Notification::make()
                        ->title('Import already running')
                        ->body('An import for this dataset is already queued or in progress. Refresh this page to monitor its status.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Import queued')
                    ->body('Refresh this page in a few minutes to see a new import run.')
                    ->success()
                    ->send();
            });
    }

    private static function canCurateCatalog(): bool
    {
        return auth('admin')->user()?->can(Permissions::CatalogManage) ?? false;
    }
}
