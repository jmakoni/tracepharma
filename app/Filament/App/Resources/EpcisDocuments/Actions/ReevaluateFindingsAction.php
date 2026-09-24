<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EpcisDocuments\Actions;

use App\Actions\Epcis\ReevaluateEpcisDocumentFindings;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class ReevaluateFindingsAction
{
    public static function forDocument(callable $document): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('reevaluateFindings')
                ->label('Re-evaluate findings')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Re-evaluate ingest findings?')
                ->modalDescription('Runs the current validator on the stored file. It does not reprocess events, receiving sessions, or aggregation links. Cases whose type is no longer emitted are cleared.')
                ->visible(fn (): bool => JobRoleAccess::allowsAny(
                    Permissions::NavExceptions,
                    Permissions::NavIntegrations,
                ))
                ->action(function () use ($document): void {
                    $record = $document();
                    if (! $record instanceof EpcisDocument) {
                        return;
                    }

                    self::run($record);
                }),
            'epcis_reevaluate_findings',
            requireReason: false,
        );
    }

    public static function forExceptionCase(callable $case): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('reevaluateFindings')
                ->label('Re-evaluate findings')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Re-evaluate ingest findings?')
                ->modalDescription('Runs the current validator on the linked document’s stored file. Events and receiving sessions are not rewritten.')
                ->visible(function () use ($case): bool {
                    if (! JobRoleAccess::allowsAny(Permissions::NavExceptions, Permissions::NavIntegrations)) {
                        return false;
                    }

                    $record = $case();

                    return $record instanceof ExceptionCase
                        && $record->status?->isOpen() === true
                        && $record->document_id !== null;
                })
                ->action(function () use ($case): void {
                    $record = $case();
                    if (! $record instanceof ExceptionCase) {
                        return;
                    }

                    $document = $record->document;
                    if ($document === null) {
                        Notification::make()
                            ->title('No linked document')
                            ->warning()
                            ->send();

                        return;
                    }

                    self::run($document);
                }),
            'exception_reevaluate_findings',
            requireReason: false,
        );
    }

    public static function run(EpcisDocument $document): array
    {
        try {
            $result = app(ReevaluateEpcisDocumentFindings::class)->handle($document, auth()->user());
        } catch (Throwable $e) {
            Notification::make()
                ->title('Re-evaluate failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return ['cleared' => [], 'left_open' => [], 'emitted' => []];
        }

        Notification::make()
            ->title('Findings re-evaluated')
            ->body(count($result['cleared']).' cleared · '.count($result['left_open']).' still emitted')
            ->success()
            ->send();

        return $result;
    }
}
