<?php

namespace App\Filament\App\Resources\SsccLabels\Pages;

use App\Actions\Labeling\DispatchSsccBatchPrint;
use App\Actions\Labeling\EmitSsccBatchCommissioningEpcis;
use App\Actions\Labeling\EmitSsccBatchEpcis;
use App\Actions\Labeling\EmitSsccDisaggregationEpcis;
use App\Actions\Labeling\EnsureSsccLabelPdf;
use App\Enums\ClientPrintBridge;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccLabelPrintStatus;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Filament\Concerns\DispatchesClientLabelPrint;
use App\Models\LabelPrinter;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccPrintJob;
use App\Models\User;
use App\Services\Labeling\SsccChildCustodyGuard;
use App\Services\Labeling\SsccLabelChildAttacher;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class ViewSsccLabelBatch extends Page
{
    use DispatchesClientLabelPrint;

    protected static string $resource = SsccLabelResource::class;

    protected static ?string $title = 'SSCC label batch';

    protected string $view = 'filament.app.pages.view-sscc-label-batch';

    #[Locked]
    public SsccLabelBatch $batch;

    public ?int $editingLabelId = null;

    public string $childEpcsText = '';

    public function mount(int|string $record): void
    {
        $this->batch = $this->loadBatch($record);

        abort_unless(SsccLabelResource::canAccess(), 403);
    }

    private function loadBatch(int|string $record): SsccLabelBatch
    {
        return SsccLabelResource::constrainBatchQuery(
            SsccLabelBatch::query()
                ->with([
                    'labels.children',
                    'labels.printer',
                    'labels.printJobs' => fn ($query) => $query->latest('id'),
                    'printJobs' => fn ($query) => $query->latest('id'),
                    'creator',
                    'printer',
                    'commissionSite',
                    'tradingPartner',
                    'sourceDocument',
                ]),
        )->findOrFail($record);
    }

    private function assertCanMutateBatch(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $siteId = $this->batch->commission_site_id;

        if ($siteId === null) {
            abort_unless($user->can(Permissions::SitesAccessAll), 403);

            return;
        }

        SiteAccess::assertCanAccessSite($user, (int) $siteId);
    }

    public function getBreadcrumbs(): array
    {
        return [
            SsccLabelResource::getUrl() => 'SSCC Labels',
            'Batch #'.$this->batch->id,
        ];
    }

    public function openChildrenEditor(int $labelId): void
    {
        $label = $this->findLabel($labelId);
        $this->editingLabelId = $label->id;
        $this->childEpcsText = $label->children->pluck('child_epc')->implode("\n");
    }

    public function saveChildren(): void
    {
        $this->assertCanMutateBatch();

        if ($this->editingLabelId === null) {
            return;
        }

        $label = $this->findLabel($this->editingLabelId);

        // Editing children here rewrites what the parent claims to contain, so the submitted
        // list faces the same custody + quarantine gate as a scan at the pack workstation.
        // Removing an offending child still saves — it is no longer part of the claim.
        try {
            app(SsccChildCustodyGuard::class)->assertMultilineOperable($this->childEpcsText);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Child EPCs not saved')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        try {
            app(SsccLabelChildAttacher::class)->syncLabel($label, $this->childEpcsText);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Child EPCs not saved')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->editingLabelId = null;
        $this->childEpcsText = '';
        $this->batch->load(['labels.children']);

        Notification::make()
            ->title('Child EPCs saved')
            ->success()
            ->send();
    }

    public function cancelChildrenEditor(): void
    {
        $this->editingLabelId = null;
        $this->childEpcsText = '';
    }

    public function emitEpcis(): void
    {
        $this->assertCanMutateBatch();

        try {
            $batch = $this->batch->fresh(['labels.children']);

            // The aggregation asserts we hold every child, so re-gate them at emit time:
            // custody or a hold can change between attaching the children and this click.
            app(SsccChildCustodyGuard::class)->assertBatchChildrenOperable($batch);

            app(EmitSsccBatchEpcis::class)->execute($batch, ['sync' => true]);
            $this->batch->refresh();

            Notification::make()
                ->title('Aggregation EPCIS emitted')
                ->success()
                ->send();
        } catch (\InvalidArgumentException|DomainException $exception) {
            Notification::make()
                ->title('Unable to emit EPCIS')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function emitDisaggregation(): void
    {
        $this->assertCanMutateBatch();

        try {
            $batch = $this->batch->fresh(['labels.children']);

            app(SsccChildCustodyGuard::class)->assertBatchChildrenOperable($batch, 'unpacking');

            app(EmitSsccDisaggregationEpcis::class)->execute($batch, ['sync' => true]);
            $this->batch->refresh();

            Notification::make()
                ->title('Disaggregation EPCIS emitted')
                ->success()
                ->send();
        } catch (\InvalidArgumentException|DomainException $exception) {
            Notification::make()
                ->title('Unable to emit disaggregation')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function commissionNow(array $data): void
    {
        $this->assertCanMutateBatch();

        $siteId = isset($data['site_id']) && $data['site_id'] !== '' && $data['site_id'] !== null
            ? (int) $data['site_id']
            : null;

        if ($siteId === null || $siteId <= 0) {
            Notification::make()
                ->title('Select a commission site')
                ->warning()
                ->send();

            return;
        }

        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        SiteAccess::assertCanAccessSite($user, $siteId);

        if (! EligibleReceiveSites::query($user)->whereKey($siteId)->exists()) {
            Notification::make()
                ->title('Invalid commission site')
                ->body('Commission site must be an active organization facility with a GLN.')
                ->danger()
                ->send();

            return;
        }

        try {
            $batch = $this->batch->fresh(['labels']);

            $pendingBefore = $batch->labels
                ->filter(fn (SsccLabel $label): bool => $label->commissioned_at === null)
                ->count();

            if ($pendingBefore === 0) {
                Notification::make()
                    ->title('Nothing to commission')
                    ->body('All labels in this batch are already commissioned.')
                    ->warning()
                    ->send();

                return;
            }

            // Site is validated above, so it is safe to persist before the emitter runs.
            if (Schema::hasColumn('sscc_label_batches', 'commission_site_id')) {
                $batch->update(['commission_site_id' => $siteId]);
            }

            app(EmitSsccBatchCommissioningEpcis::class)->execute($batch->fresh(['labels']), [
                'site_id' => $siteId,
                'sync' => true,
            ]);

            $this->batch = $batch->fresh([
                'labels.children',
                'labels.printer',
                'labels.printJobs' => fn ($query) => $query->latest('id'),
                'printJobs' => fn ($query) => $query->latest('id'),
                'creator',
                'printer',
                'commissionSite',
                'tradingPartner',
                'sourceDocument',
            ]);

            $stillPending = $this->batch->labels
                ->filter(fn (SsccLabel $label): bool => $label->commissioned_at === null)
                ->count();

            if ($this->batch->commissioned_at === null || $stillPending >= $pendingBefore) {
                Notification::make()
                    ->title('Nothing to commission')
                    ->body('Commissioning did not complete for any label. Check the site and try again.')
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title('SSCC batch commissioned')
                ->success()
                ->send();

            // A batch whose commissioning failed at generation is left Failed; a successful retry
            // clears that line and restores it to Completed.
            if ($this->batch->hasCommissioningError()) {
                $cleared = collect(preg_split("/\r\n|\n|\r/", (string) $this->batch->error_message) ?: [])
                    ->reject(fn (string $line): bool => str_contains($line, 'Commissioning:'))
                    ->implode("\n");

                $this->batch->update([
                    'error_message' => filled(trim($cleared)) ? $cleared : null,
                    'status' => SsccLabelBatchStatus::Completed,
                    'completed_at' => $this->batch->completed_at ?? now(),
                ]);
                $this->batch = $this->batch->fresh([
                    'labels.children',
                    'labels.printer',
                    'labels.printJobs' => fn ($query) => $query->latest('id'),
                    'printJobs' => fn ($query) => $query->latest('id'),
                    'creator',
                    'printer',
                    'commissionSite',
                    'tradingPartner',
                    'sourceDocument',
                ]);
            }
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Unable to commission batch')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reprintBatch(array $data): void
    {
        $this->assertCanMutateBatch();

        if ($this->batch->commissioned_at === null) {
            Notification::make()
                ->title('Commission the batch first')
                ->body('SSCC labels can only be printed after the batch is commissioned.')
                ->warning()
                ->send();

            return;
        }

        $printerId = (int) ($data['label_printer_id'] ?? 0);

        if ($printerId <= 0) {
            Notification::make()
                ->title('Select a printer')
                ->warning()
                ->send();

            return;
        }

        $this->batch->update([
            'label_printer_id' => $printerId,
            'send_to_printer' => true,
            'copies_per_label' => max(1, (int) ($data['copies_per_label'] ?? $this->batch->copies_per_label)),
        ]);

        $bridgeOverride = ClientPrintBridge::tryFromMixed($data['client_print_bridge'] ?? null);

        try {
            $result = app(DispatchSsccBatchPrint::class)->execute(
                $this->batch->fresh('labels'),
                $bridgeOverride,
            );
        } catch (\InvalidArgumentException|LockTimeoutException $exception) {
            Notification::make()
                ->title('Print not started')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->notifyPrintDispatchResult($result, queuedTitle: 'Print jobs queued');
    }

    public function retryPrintLabel(int $labelId): void
    {
        $this->assertCanMutateBatch();

        $label = $this->findLabel($labelId);

        if (! $label->printHasFailed()) {
            Notification::make()
                ->title('Print retry unavailable')
                ->body('Only failed labels can be retried.')
                ->warning()
                ->send();

            return;
        }

        $printerId = (int) ($label->label_printer_id ?? $this->batch->label_printer_id ?? 0);
        if ($printerId <= 0) {
            Notification::make()
                ->title('Select a printer')
                ->body('Assign a printer on the batch, then retry.')
                ->warning()
                ->send();

            return;
        }

        $copies = max(1, (int) ($this->batch->copies_per_label ?: 1));

        try {
            $result = app(DispatchSsccBatchPrint::class)->forLabel($label, $printerId, $copies);
        } catch (\InvalidArgumentException|LockTimeoutException $exception) {
            Notification::make()
                ->title('Print not started')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->notifyPrintDispatchResult($result, queuedTitle: 'Print job re-queued');
    }

    #[On('client-print-done')]
    public function onClientPrintDone(int $printed = 0, int $failed = 0, string $bridge = ''): void
    {
        $this->batch = $this->loadBatch($this->batch->id);

        $bridgeLabel = ClientPrintBridge::tryFrom($bridge)?->shortLabel() ?? $bridge;

        if ($failed > 0) {
            Notification::make()
                ->title('Client print finished with errors')
                ->body("{$printed} printed, {$failed} failed via {$bridgeLabel}.")
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Client print complete')
            ->body("{$printed} label(s) sent via {$bridgeLabel}.")
            ->success()
            ->send();
    }

    #[On('client-print-error')]
    public function onClientPrintError(string $message = ''): void
    {
        Notification::make()
            ->title('Client print failed')
            ->body($message !== '' ? $message : 'Check that the local print agent is running.')
            ->danger()
            ->send();
    }

    /**
     * @param  array{
     *     mode: 'network'|'client',
     *     bridge: string,
     *     jobs: list<array<string, mixed>>
     * }  $result
     */
    private function notifyPrintDispatchResult(array $result, string $queuedTitle): void
    {
        $this->batch = $this->loadBatch($this->batch->id);

        if ($result['mode'] === 'client' && $result['jobs'] !== []) {
            $bridge = ClientPrintBridge::tryFrom($result['bridge']);
            $this->dispatchClientLabelPrint($result);

            Notification::make()
                ->title('Sending to '.($bridge?->shortLabel() ?? 'client printer').' on this workstation…')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title($queuedTitle)
            ->success()
            ->send();
    }

    public function latestPrintJobForLabel(SsccLabel $label): ?SsccPrintJob
    {
        $jobs = $label->relationLoaded('printJobs')
            ? $label->printJobs
            : $label->printJobs()->latest('id')->get();

        return $jobs->first();
    }

    public function printStatusBadgeClass(?SsccLabelPrintStatus $status): string
    {
        return match ($status) {
            SsccLabelPrintStatus::Failed => 'badge badge-error',
            SsccLabelPrintStatus::Printed => 'badge badge-success',
            SsccLabelPrintStatus::Queued => 'badge badge-info',
            SsccLabelPrintStatus::Skipped => 'badge badge-ghost',
            default => 'badge badge-ghost',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reprintBatch')
                ->label('Reprint batch')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->visible(fn (): bool => $this->batch->commissioned_at !== null)
                ->form([
                    Select::make('label_printer_id')
                        ->label('Printer')
                        ->options(fn (): array => LabelPrinter::query()
                            ->where('enabled', true)
                            ->orderByDesc('is_default')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (LabelPrinter $printer): array => [$printer->id => $printer->displayName()])
                            ->all())
                        ->default($this->batch->label_printer_id)
                        ->required()
                        ->searchable(),
                    TextInput::make('copies_per_label')
                        ->label('Copies per label')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->default($this->batch->copies_per_label)
                        ->required(),
                    Select::make('client_print_bridge')
                        ->label('Print bridge override')
                        ->options(collect(ClientPrintBridge::cases())->mapWithKeys(
                            fn (ClientPrintBridge $bridge): array => [$bridge->value => $bridge->label()]
                        ))
                        ->placeholder('Use printer protocol')
                        ->native(false)
                        ->helperText('Leave blank to follow the selected printer’s protocol (Network / QZ Tray / Zebra Browser Print).'),
                ])
                ->action(fn (array $data) => $this->reprintBatch($data)),
            Action::make('commissionNow')
                ->label('Commission now')
                ->icon('heroicon-o-check-badge')
                ->visible(fn (): bool => $this->batch->commissioned_at === null)
                ->form([
                    Select::make('site_id')
                        ->label('Commission site')
                        ->options(fn (): array => EligibleReceiveSites::options())
                        ->default(fn (): ?int => $this->defaultCommissionSiteId())
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText('Used as commissioning readPoint/bizLocation.'),
                ])
                ->action(fn (array $data) => $this->commissionNow($data)),
            Action::make('emitDisaggregation')
                ->label('Emit disaggregation EPCIS')
                ->icon('heroicon-o-scissors')
                ->visible(fn (): bool => $this->batch->commissioned_at !== null
                    && $this->batch->source_parent_sscc_urn !== null)
                ->action('emitDisaggregation'),
            Action::make('emitEpcis')
                ->label('Emit aggregation EPCIS')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $this->batch->commissioned_at !== null)
                ->action('emitEpcis'),
            Action::make('downloadAll')
                ->label('Download all PDFs')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->visible(fn (): bool => $this->batch->commissioned_at !== null)
                ->action(fn (): ?BinaryFileResponse => $this->downloadAllLabels()),
        ];
    }

    public function downloadLabel(int $labelId): ?StreamedResponse
    {
        $label = $this->findLabel($labelId);

        if ($label->commissioned_at === null && $this->batch->commissioned_at === null) {
            Notification::make()
                ->title('Commission the batch first')
                ->body('The PDF is available after the label is commissioned.')
                ->warning()
                ->send();

            return null;
        }

        try {
            $label = app(EnsureSsccLabelPdf::class)->handle($label);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('PDF unavailable')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        return Storage::disk($label->label_disk)->download(
            $label->label_path,
            "sscc-{$label->sscc_18}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    protected function downloadAllLabels(): ?BinaryFileResponse
    {
        if ($this->batch->commissioned_at === null) {
            Notification::make()
                ->title('Commission the batch first')
                ->body('Download all PDFs is available after the batch is commissioned.')
                ->warning()
                ->send();

            return null;
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'sscc-batch-');
        $zipFile = $zipPath.'.zip';
        rename($zipPath, $zipFile);

        $zip = new ZipArchive;
        $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $ensure = app(EnsureSsccLabelPdf::class);
        $added = 0;
        $skipped = 0;

        foreach ($this->batch->labels as $label) {
            try {
                $label = $ensure->handle($label);
            } catch (Throwable) {
                $skipped++;

                continue;
            }

            if ($label->label_path === null || $label->label_disk === null) {
                $skipped++;

                continue;
            }

            $contents = Storage::disk($label->label_disk)->get($label->label_path);
            $zip->addFromString("sscc-{$label->sscc_18}.pdf", $contents);
            $added++;
        }

        $zip->close();

        if ($added === 0) {
            @unlink($zipFile);

            Notification::make()
                ->title('No PDFs available')
                ->body('None of the labels in this batch could be prepared for download.')
                ->danger()
                ->send();

            return null;
        }

        if ($skipped > 0) {
            Notification::make()
                ->title('Some labels skipped')
                ->body("{$skipped} label(s) could not be prepared and were left out of the download.")
                ->warning()
                ->send();
        }

        return response()->download($zipFile, "sscc-batch-{$this->batch->id}.zip")->deleteFileAfterSend();
    }

    private function findLabel(int $labelId): SsccLabel
    {
        return $this->batch->labels()->findOrFail($labelId);
    }

    private function defaultCommissionSiteId(): ?int
    {
        $settings = TenantSettings::forTenant(tenant());
        $candidate = $settings->defaultShipFromSiteId()
            ?? $settings->defaultReceiveSiteId();

        $user = auth()->user();
        $user = $user instanceof User ? $user : null;

        $fallback = null;
        if ($candidate !== null && EligibleReceiveSites::query($user)->whereKey($candidate)->exists()) {
            $fallback = $candidate;
        } else {
            $first = EligibleReceiveSites::query($user)->value('id');
            $fallback = $first !== null ? (int) $first : null;
        }

        return CurrentSite::preferredId(
            $fallback,
            EligibleReceiveSites::options($user),
        );
    }
}
