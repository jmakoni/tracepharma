<?php

namespace App\Filament\App\Resources\Exceptions\Schemas;

use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Models\Exceptions\ExceptionAction;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Support\Exceptions\AssortmentFromCatalog;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use Database\Seeders\ExceptionCaseSeeder;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExceptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Details')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('title')
                        ->columnSpanFull(),
                    TextEntry::make('description')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('suggested_correction')
                        ->label('Suggested correction')
                        ->state(function (ExceptionCase $record): string {
                            $profile = ExceptionCorrectionProfile::forCase($record);
                            $fingerprint = ExceptionCorrectionProfile::extractGtinFromDescription($record->description);

                            if (
                                $record->status?->isOpen() === true
                                && $fingerprint !== null
                                && AssortmentFromCatalog::productAuthorizedForGtin($fingerprint)
                            ) {
                                return $record->document_id !== null
                                    ? 'This GTIN is now in product master. Re-process the linked document to close the case.'
                                    : 'This GTIN is now in product master. Resolve the case from More when ready.';
                            }

                            return sprintf(
                                '%s Suggested action: %s.',
                                $profile->suggestedCorrectionBlurb(),
                                $profile->primaryActionLabel(),
                            );
                        })
                        ->columnSpanFull(),
                    TextEntry::make('type.name')
                        ->label('Type')
                        ->placeholder('—'),
                    TextEntry::make('type.receive_impact')
                        ->label('Receive impact')
                        ->badge()
                        ->formatStateUsing(fn (?ExceptionReceiveImpact $state): ?string => $state?->label())
                        ->color(fn (?ExceptionReceiveImpact $state): string => $state?->badgeColor() ?? 'gray')
                        ->placeholder('—'),
                    TextEntry::make('severity')
                        ->badge()
                        ->formatStateUsing(fn (?ExceptionSeverity $state): ?string => $state?->label())
                        ->color(fn (?ExceptionSeverity $state): string => $state?->badgeColor() ?? 'gray'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?ExceptionStatus $state): ?string => $state?->label())
                        ->color(fn (?ExceptionStatus $state): string => $state?->badgeColor() ?? 'gray'),
                    TextEntry::make('tradingPartner.name')
                        ->label('Partner')
                        ->placeholder('—'),
                    TextEntry::make('serials_affected')
                        ->label('Serials affected')
                        ->numeric()
                        ->placeholder('0'),
                    TextEntry::make('due_at')
                        ->label('Due')
                        ->dateTime()
                        ->placeholder('—')
                        ->color(fn (ExceptionCase $record): ?string => $record->isOverdue() ? 'danger' : null),
                    TextEntry::make('assignee.name')
                        ->label('Assignee')
                        ->placeholder('—'),
                ]),
            Section::make('Document')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('document_id')
                        ->label('Document')
                        ->formatStateUsing(fn (?int $state): string => $state ? '#'.$state : '—')
                        ->url(fn (ExceptionCase $record): ?string => $record->document_id
                            ? EpcisDocumentResource::getUrl('view', ['record' => $record->document_id], panel: 'app')
                            : null)
                        ->color('primary'),
                    TextEntry::make('document.original_filename')
                        ->label('Filename')
                        ->placeholder('—'),
                    TextEntry::make('document.document_uuid')
                        ->label('UUID')
                        ->placeholder('—')
                        ->copyable()
                        ->columnSpanFull(),
                ]),
            Section::make('Resolution')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('rootCause.name')
                        ->label('Root cause')
                        ->placeholder('—')
                        ->visible(fn (ExceptionCase $record): bool => $record->root_cause_id !== null),
                    TextEntry::make('suggested_root_cause')
                        ->label('Suggested root cause')
                        ->state(fn (ExceptionCase $record): ?string => self::suggestedRootCauseName($record))
                        ->placeholder('—')
                        ->visible(fn (ExceptionCase $record): bool => $record->root_cause_id === null
                            && self::suggestedRootCauseName($record) !== null),
                    TextEntry::make('resolutionAction.name')
                        ->label('Resolution action')
                        ->placeholder('—')
                        ->visible(fn (ExceptionCase $record): bool => $record->resolution_action_id !== null),
                    TextEntry::make('suggested_resolution_action')
                        ->label('Suggested resolution action')
                        ->state(fn (ExceptionCase $record): ?string => self::suggestedResolutionActionName($record))
                        ->placeholder('—')
                        ->visible(fn (ExceptionCase $record): bool => $record->resolution_action_id === null
                            && self::suggestedResolutionActionName($record) !== null),
                    TextEntry::make('resolution_notes')
                        ->label('Notes')
                        ->markdown()
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function suggestedRootCauseName(ExceptionCase $record): ?string
    {
        $code = ExceptionCorrectionProfile::forCase($record)->suggestedRootCauseCode();

        if ($code === null || $code === '') {
            return null;
        }

        ExceptionCaseSeeder::ensureResolutionCatalog();

        $name = ExceptionRootCause::query()->where('code', $code)->value('name');

        return filled($name) ? (string) $name : null;
    }

    private static function suggestedResolutionActionName(ExceptionCase $record): ?string
    {
        $code = ExceptionCorrectionProfile::forCase($record)->suggestedResolutionActionCode();

        if ($code === null || $code === '') {
            return null;
        }

        ExceptionCaseSeeder::ensureResolutionCatalog();

        $name = ExceptionAction::query()->where('code', $code)->value('name');

        return filled($name) ? (string) $name : null;
    }
}
