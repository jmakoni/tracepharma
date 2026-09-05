<?php

namespace App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Pages;

use App\Actions\Fda\PromoteFdaWdd3plToCatalogSites;
use App\Exceptions\FdaStagingCollapsedException;
use App\Exceptions\FdaStagingImportIncompleteException;
use App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\FdaWdd3plStagingResource;
use App\Jobs\ImportFdaDatasetJob;
use App\Jobs\SyncTenantAtpLicensesFromFda;
use App\Models\Fda\FdaWdd3plStaging;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Auth\Permissions;
use App\Support\Fda\FdaStagingSnapshotSize;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListFdaWdd3plStagings extends ListRecords
{
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
            Action::make('promoteToCatalog')
                ->label('Promote to catalog')
                ->icon(Heroicon::OutlinedArrowUpCircle)
                ->color('primary')
                ->visible(false)
                ->authorize(fn (): bool => self::canCurateCatalog())
                ->requiresConfirmation()
                ->modalHeading('Promote staging to catalog')
                ->modalDescription(function (): string {
                    $description = 'Creates or updates catalog sites and ATP license records from staging rows, marks licenses this snapshot no longer lists as dropped from the FDA listing, then queues tenant ATP sync. Records the listing only — it does not authorize partners.';
                    $size = FdaStagingSnapshotSize::measure();

                    return $size->hasCollapsed()
                        ? $size->summary().' Promoting a short load would mark the facilities it left out as dropped from the FDA listing. '.$description
                        : $description;
                })
                // The override only appears when there is something to override, so the
                // usual promote stays a single confirmation.
                ->schema(fn (): array => FdaStagingSnapshotSize::measure()->hasCollapsed()
                    ? [
                        Toggle::make('force')
                            ->label('Promote anyway')
                            ->helperText('Staging holds far fewer rows than the last import. Only promote if the short file is genuinely what the FDA published.')
                            ->default(false),
                    ]
                    : [])
                ->action(function (array $data): void {
                    abort_unless(self::canCurateCatalog(), 403);

                    try {
                        $counts = app(PromoteFdaWdd3plToCatalogSites::class)
                            ->handle(false, (bool) ($data['force'] ?? false));
                    } catch (FdaStagingImportIncompleteException|FdaStagingCollapsedException $exception) {
                        Notification::make()
                            ->title('Promotion blocked')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    SyncTenantAtpLicensesFromFda::dispatchForAllTenants();

                    Notification::make()
                        ->title('Promotion complete')
                        ->body(
                            'Processed: '.$counts['processed']
                            .' · Sites created: '.$counts['sites_created']
                            .' · Sites matched: '.$counts['sites_matched']
                            .' · Licenses upserted: '.$counts['licenses_upserted']
                            .' · Licenses relocated: '.$counts['licenses_relocated']
                            .' · Licenses delisted: '.$counts['licenses_delisted']
                            .' · Skipped: '.$counts['skipped']
                            .' · Unreadable expirations: '.$counts['expirations_unparsed']
                            .' · Tenant ATP sync queued.'
                        )
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Import truncates staging and promote rewrites the catalog sites and ATP licences
     * every tenant resolves against, delisting whatever the snapshot leaves out — the same
     * reach the catalog policies already gate, so both need the catalog permission.
     */
    private static function canCurateCatalog(): bool
    {
        return auth('admin')->user()?->can(Permissions::CatalogManage) ?? false;
    }
}
