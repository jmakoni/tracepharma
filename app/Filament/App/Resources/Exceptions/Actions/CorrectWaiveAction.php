<?php

namespace App\Filament\App\Resources\Exceptions\Actions;

use App\Enums\ExceptionStatus;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Exceptions\ExceptionAction as ExceptionActionModel;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use App\Support\Filament\ProseEditor;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Accepts an exception without a data correction — either as a documented waiver or as a
 * false-positive determination — for exception types where {@see ExceptionCorrectionProfile}
 * flags waiving as an appropriate resolution path.
 */
final class CorrectWaiveAction
{
    private const DISPOSITION_WAIVER = 'waiver';

    private const DISPOSITION_FALSE_POSITIVE = 'false_positive';

    public static function make(ViewException $page): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('acceptWithWaiver')
                ->label('Accept with waiver')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->visible(function () use ($page): bool {
                    /** @var ExceptionCase $record */
                    $record = $page->getRecord();
                    $status = $record->status;

                    $canResolve = $status === ExceptionStatus::Investigating
                        || $status->allowsTransitionTo(ExceptionStatus::Resolved);

                    return ExceptionCorrectionProfile::showsWaiveForCase($record)
                        && $status?->isOpen() === true
                        && $canResolve
                        && ! $record->hasBlockingOpenQuarantineHolds();
                })
                ->modalHeading('Accept with waiver')
                ->schema(function () use ($page): array {
                    $profile = self::profileFor($page);

                    return [
                        Select::make('disposition_choice')
                            ->label('Disposition')
                            ->options([
                                self::DISPOSITION_WAIVER => 'Accept with waiver',
                                self::DISPOSITION_FALSE_POSITIVE => 'No action — false positive',
                            ])
                            ->default(self::DISPOSITION_WAIVER)
                            ->required(),
                        Select::make('root_cause_id')
                            ->label('Root cause')
                            ->options(fn (): array => ExceptionRootCause::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->default(fn (): ?int => ExceptionRootCause::query()
                                ->where('code', $profile->suggestedRootCauseCode() ?? 'unknown')
                                ->value('id'))
                            ->searchable()
                            ->required(),
                        ProseEditor::make('notes')
                            ->label('Waiver notes')
                            ->required()
                            ->helperText('Document why this is accepted without a data correction.'),
                    ];
                })
                ->action(function (array $data) use ($page): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $page->getRecord();

                    $actionCode = (string) ($data['disposition_choice'] ?? self::DISPOSITION_WAIVER) === self::DISPOSITION_FALSE_POSITIVE
                        ? 'no_action_false_positive'
                        : 'accept_with_waiver';

                    $resolutionActionId = ExceptionActionModel::query()->where('code', $actionCode)->value('id');

                    if ($resolutionActionId === null) {
                        Notification::make()
                            ->title('Waiver failed')
                            ->body('Resolution catalog is missing the "'.$actionCode.'" code.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        app(ExceptionService::class)->resolve(
                            $record,
                            $actor,
                            (int) $data['root_cause_id'],
                            (int) $resolutionActionId,
                            (string) $data['notes'],
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Waiver failed')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Waiver failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $page->refreshRecord();

                    Notification::make()
                        ->title('Exception accepted with waiver')
                        ->success()
                        ->send();
                }),
            'exception_waive',
            requireReason: true,
            existingReasonField: 'notes',
        );
    }

    private static function profileFor(ViewException $page): ExceptionCorrectionProfile
    {
        /** @var ExceptionCase $record */
        $record = $page->getRecord();

        return ExceptionCorrectionProfile::forCase($record);
    }
}
