<?php

namespace App\Filament\App\Resources\SsccLabels\Pages;

use App\Actions\Labeling\BreakPalletAndReship;
use App\Actions\Labeling\GenerateSsccLabelBatch;
use App\Enums\ClientPrintBridge;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccReshipMode;
use App\Filament\App\Resources\SsccLabels\Schemas\BreakPalletForm;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Filament\Concerns\DispatchesClientLabelPrint;
use App\Models\LabelPrinter;
use App\Models\SsccLabelBatch;
use App\Support\TenantFeatures;
use App\Support\TenantSsccSettings;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class ListSsccLabels extends ListRecords
{
    use DispatchesClientLabelPrint;

    protected static string $resource = SsccLabelResource::class;

    protected ?SsccLabelBatch $ssccLabelBatch = null;

    public ?string $pendingRedirectUrl = null;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Generate SSCC labels')
                ->modalHeading('Generate SSCC label batch')
                ->modalSubmitActionLabel('Generate')
                ->createAnother(false)
                ->fillForm(function (): array {
                    $settings = TenantSsccSettings::resolve();

                    return [
                        'allocation_mode' => $settings['default_allocation_mode'] instanceof SsccAllocationMode
                            ? $settings['default_allocation_mode']->value
                            : SsccAllocationMode::Sequential->value,
                        'enforce_forward_only' => $settings['enforce_forward_only'],
                        'label_count' => 1,
                        'copies_per_label' => 1,
                        'label_printer_id' => LabelPrinter::query()->where('enabled', true)->where('is_default', true)->value('id'),
                    ];
                })
                ->using(function (array $data): Model {
                    try {
                        $batch = app(GenerateSsccLabelBatch::class)->execute($data);

                        $this->ssccLabelBatch = $batch;
                        $this->dispatchClientLabelPrint($batch->printDispatch);

                        return $batch->labels()->firstOrFail();
                    } catch (\InvalidArgumentException $exception) {
                        throw ValidationException::withMessages([
                            'allocation_mode' => $exception->getMessage(),
                        ]);
                    }
                })
                ->successRedirectUrl(function (): ?string {
                    if ($this->ssccLabelBatch !== null && $this->ssccLabelBatch->label_count > 1) {
                        return SsccLabelResource::getUrl('view-batch', ['record' => $this->ssccLabelBatch]);
                    }

                    return SsccLabelResource::getUrl('index');
                })
                ->successNotification(function (): Notification {
                    $batch = $this->ssccLabelBatch;

                    if ($batch !== null && $batch->hasErrors()) {
                        return Notification::make()
                            ->warning()
                            ->title('SSCC labels generated with warnings')
                            ->body(implode("\n", $batch->errorLines()));
                    }

                    return Notification::make()
                        ->success()
                        ->title('SSCC labels generated');
                }),
            Action::make('breakPalletAndReship')
                ->label('Break pallet & re-label')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->modalHeading('Break pallet & re-label')
                ->modalDescription(
                    'Breaks an inbound pallet hierarchy and generates new outbound SSCC labels. '
                    .'This authors disaggregation and aggregation EPCIS only — it does NOT create a shipping event. '
                    .'Complete outbound shipping separately once the new pallets are staged.'
                )
                ->modalSubmitActionLabel('Generate new SSCC labels')
                ->visible(fn (): bool => self::canBreakPallet())
                ->fillForm(function (): array {
                    $settings = TenantSsccSettings::resolve();

                    return [
                        'allocation_mode' => $settings['default_allocation_mode'] instanceof SsccAllocationMode
                            ? $settings['default_allocation_mode']->value
                            : SsccAllocationMode::Sequential->value,
                        'enforce_forward_only' => $settings['enforce_forward_only'],
                        'reship_mode' => SsccReshipMode::PerChild->value,
                        'label_count' => 1,
                        'copies_per_label' => 1,
                        'emit_disaggregation' => true,
                        'emit_epcis' => true,
                        'send_to_printer' => false,
                        'selected_child_epcs' => [],
                        'label_printer_id' => LabelPrinter::query()->where('enabled', true)->where('is_default', true)->value('id'),
                    ];
                })
                ->form(BreakPalletForm::components())
                ->action(function (array $data): void {
                    $data = BreakPalletForm::normalizeInput($data);

                    try {
                        $batch = app(BreakPalletAndReship::class)->execute($data);
                    } catch (\InvalidArgumentException $exception) {
                        throw ValidationException::withMessages([
                            'source_epcis_document_id' => $exception->getMessage(),
                        ]);
                    }

                    $this->ssccLabelBatch = $batch;
                    $this->dispatchClientLabelPrint($batch->printDispatch);

                    if ($batch->hasErrors()) {
                        Notification::make()
                            ->warning()
                            ->title('New SSCC labels generated with warnings')
                            ->body("Batch #{$batch->id} with {$batch->label_count} label(s).\n".implode("\n", $batch->errorLines()))
                            ->send();
                    } else {
                        Notification::make()
                            ->success()
                            ->title('New SSCC labels generated')
                            ->body("Batch #{$batch->id} with {$batch->label_count} label(s). No shipping event was created.")
                            ->send();
                    }

                    $redirectUrl = SsccLabelResource::getUrl('view-batch', ['record' => $batch]);
                    $printDispatch = $batch->printDispatch;

                    if (
                        $printDispatch !== null
                        && ($printDispatch['mode'] ?? '') === 'client'
                        && ($printDispatch['jobs'] ?? []) !== []
                    ) {
                        $this->pendingRedirectUrl = $redirectUrl;

                        return;
                    }

                    $this->redirect($redirectUrl);
                }),
        ];
    }

    public static function canBreakPallet(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        return $features->supportsPacking() || $features->supportsSsccLabeling();
    }

    #[On('client-print-done')]
    public function onClientPrintDone(int $printed = 0, int $failed = 0, string $bridge = ''): void
    {
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

        if ($this->pendingRedirectUrl !== null) {
            $url = $this->pendingRedirectUrl;
            $this->pendingRedirectUrl = null;
            $this->redirect($url);
        }
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
}
