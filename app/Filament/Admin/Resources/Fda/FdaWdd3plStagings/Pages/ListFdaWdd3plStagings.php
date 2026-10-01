<?php

namespace App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Pages;

use App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\FdaWdd3plStagingResource;
use App\Filament\Notifications\Notification;
use App\Jobs\ImportFdaDatasetJob;
use App\Models\Fda\FdaWdd3plStaging;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Auth\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListFdaWdd3plStagings extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = FdaWdd3plStagingResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $total = FdaWdd3plStaging::query()->count();
        $unmatchedOpen = FdaWdd3plUnmatched::query()->unresolved()->count();

        return "License listing import from the FDA WDD/3PL report — registrants self-report it, so a listing is not FDA approval or proof of licensure. {$total} staging rows · {$unmatchedOpen} unmatched open";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importWdd3pl')
                ->label('Import WDD/3PL')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->authorize(fn (): bool => self::canCurateCatalog())
                ->requiresConfirmation()
                ->modalHeading('Import FDA WDD/3PL license listing')
                ->modalDescription('Truncates staging and reloads the self-reported license listing from the FDA dataset, then refreshes WDD facilities, licenses, and match reviews in the FDA registry. This records what the FDA lists; it does not authorize partners. Unmatched facilities are tracked for triage. The import runs in the background — refresh this page after it finishes.')
                ->schema([
                    Toggle::make('fresh_download')
                        ->label('Fresh download')
                        ->helperText('Download a new copy from FDA instead of using the cached file.')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    abort_unless(self::canCurateCatalog(), 403);

                    $parameters = [];
                    if ((bool) ($data['fresh_download'] ?? false)) {
                        $parameters['--fresh-download'] = true;
                    }

                    if (! ImportFdaDatasetJob::dispatchIfIdle(ImportFdaDatasetJob::WDD_COMMAND, $parameters)) {
                        Notification::make()
                            ->title('Import already running')
                            ->body('A WDD/3PL import is already queued or in progress. Refresh this page to monitor its status.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Import queued')
                        ->body('Refresh this page in a few minutes to see updated staging and registry rows.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Import truncates staging and rewrites FDA registry facilities/licenses every
     * tenant resolves against — the same reach the catalog policies already gate.
     */
    private static function canCurateCatalog(): bool
    {
        return auth('admin')->user()?->can(Permissions::CatalogManage) ?? false;
    }
}
