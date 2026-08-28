<?php

namespace App\Filament\App\Resources\Exceptions\Actions;

use App\Actions\Exceptions\StartInvestigatorSla;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionStatus;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Adds a partner-visible note and best-effort transitions the case toward
 * {@see ExceptionStatus::WaitingPartner} for exception families where the fix originates
 * upstream (aggregation/timing mismatches, or any type whose correction profile points at
 * {@see ExceptionCorrectionProfile::ACTION_INVESTIGATE_PARTNER}).
 */
final class RequestPartnerCorrectionAction
{
    public static function make(ViewException $page): Action
    {
        return Action::make('requestPartnerCorrection')
            ->label(function () use ($page): string {
                $profile = self::profileFor($page);

                return $profile->primaryActionKey() === ExceptionCorrectionProfile::ACTION_INVESTIGATE_PARTNER
                    ? $profile->primaryActionLabel()
                    : 'Investigate & request partner correction';
            })
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('info')
            ->visible(function () use ($page): bool {
                /** @var ExceptionCase $record */
                $record = $page->getRecord();
                $profile = self::profileFor($page);

                $matchesFamily = in_array($profile->family(), [
                    ExceptionCorrectionProfile::FAMILY_AGGREGATION,
                    ExceptionCorrectionProfile::FAMILY_TIMING,
                ], true);

                return $record->status?->isOpen() === true
                    && ($profile->primaryActionKey() === ExceptionCorrectionProfile::ACTION_INVESTIGATE_PARTNER || $matchesFamily);
            })
            ->modalHeading('Request partner correction')
            ->modalDescription('Adds a partner-visible note and moves the case toward Waiting (partner) where the current status allows it.')
            ->schema([
                Textarea::make('body')
                    ->label('Note to trading partner')
                    ->required()
                    ->rows(4)
                    ->maxLength(5000)
                    ->helperText('Visible to trading partners when partner portals are enabled.'),
                Toggle::make('email_supplier')
                    ->label('Also email supplier portal link')
                    ->default(fn (): bool => filled($page->getRecord()->tradingPartner?->email))
                    ->visible(fn (): bool => filled($page->getRecord()->tradingPartner?->email))
                    ->helperText('Sends the DSCSA exception notice with a link to the supplier exception portal.'),
            ])
            ->action(function (array $data) use ($page): void {
                /** @var User $actor */
                $actor = auth()->user();
                /** @var ExceptionCase $record */
                $record = $page->getRecord();

                try {
                    app(ExceptionService::class)->addComment(
                        $record,
                        $actor,
                        (string) $data['body'],
                        ExceptionActivityVisibility::Partner,
                    );
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Request failed')
                        ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                self::moveTowardWaitingPartner($record->fresh() ?? $record, $actor);

                $emailBody = null;
                if (($data['email_supplier'] ?? false) === true) {
                    // Same path as Investigator SLA desk: send portal email and start/refresh the 72h due_at overlay.
                    $result = app(StartInvestigatorSla::class)->handle($record->fresh() ?? $record, $actor);
                    $emailBody = ($result['sent'] ?? false)
                        ? 'Supplier portal email sent. 72-hour investigator clock is running.'
                        : ('Partner note saved; email not sent: '.($result['error'] ?? 'unable to send.'));
                }

                $page->refreshRecord();

                Notification::make()
                    ->title('Partner correction requested')
                    ->body($emailBody ?? 'Partner-visible note added.')
                    ->success()
                    ->send();
            });
    }

    private static function profileFor(ViewException $page): ExceptionCorrectionProfile
    {
        /** @var ExceptionCase $record */
        $record = $page->getRecord();

        return ExceptionCorrectionProfile::forCase($record);
    }

    /**
     * Best-effort: walk the case toward WaitingPartner via whatever intermediate
     * transitions the current status allows. Leaves status untouched (partner note is
     * still recorded) if no path is available, e.g. from PendingApproval or Resolved.
     */
    private static function moveTowardWaitingPartner(ExceptionCase $record, User $actor): void
    {
        if ($record->status === ExceptionStatus::WaitingPartner) {
            return;
        }

        $service = app(ExceptionService::class);

        try {
            if ($record->status->allowsTransitionTo(ExceptionStatus::WaitingPartner)) {
                $service->transition($record, ExceptionStatus::WaitingPartner, $actor, 'Awaiting trading partner correction.');

                return;
            }

            if ($record->status === ExceptionStatus::New) {
                $record = $service->transition($record, ExceptionStatus::Triaged, $actor, 'Auto-triaged for partner correction.');
            }

            if ($record->status === ExceptionStatus::WaitingInternal) {
                $record = $service->transition($record, ExceptionStatus::Investigating, $actor, 'Resuming investigation for partner correction.');
            }

            if ($record->status->allowsTransitionTo(ExceptionStatus::WaitingPartner)) {
                $service->transition($record, ExceptionStatus::WaitingPartner, $actor, 'Awaiting trading partner correction.');
            }
        } catch (ValidationException) {
            // Best-effort: leave status as-is if a mid-chain transition fails; the
            // partner-visible note above was still recorded.
        }
    }
}
