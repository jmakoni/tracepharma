<?php

namespace App\Filament\App\Resources\SsccNumberRanges\Pages;

use App\Filament\App\Resources\SsccNumberRanges\SsccNumberRangeResource;
use App\Filament\Notifications\Notification;
use App\Models\SsccNumberRange;
use App\Support\Labeling\SsccNumberRangeValidator;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class EditSsccNumberRange extends EditRecord
{
    protected static string $resource = SsccNumberRangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalDescription(function (SsccNumberRange $record): string {
                    if ($record->hasIssuedSerials()) {
                        return 'This range has already issued serials. It will be marked Inactive so the issued band stays reserved. Delete is only allowed for never-used ranges.';
                    }

                    return 'Delete this unused number range?';
                })
                ->action(function (SsccNumberRange $record): void {
                    if ($record->hasIssuedSerials()) {
                        $record->markInactive();
                        $this->refreshFormData(['status', 'remaining', 'current_number']);

                        Notification::make()
                            ->title('Range marked inactive')
                            ->body('Issued serials are preserved; the unused tail can be replenished by a new range.')
                            ->success()
                            ->send();

                        return;
                    }

                    $record->delete();
                    Notification::make()->title('Range deleted')->success()->send();
                    $this->redirect(SsccNumberRangeResource::getUrl('index', panel: 'app'));
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SsccNumberRange $record */
        try {
            return DB::transaction(function () use ($record, $data): Model {
                $validated = SsccNumberRangeValidator::normalizeAndValidate($data, $record);
                $record->update($validated);

                return $record;
            });
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Invalid number range')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();

            throw new Halt;
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title('Could not save number range')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();

            throw new Halt;
        }
    }
}
