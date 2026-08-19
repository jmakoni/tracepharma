<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Schemas;

use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Models\Fda\FdaOrganizationMatchReview;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FdaOrganizationMatchReviewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Review')
                ->columns(2)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            FdaOrganizationMatchReview::STATUS_PENDING => 'Pending',
                            FdaOrganizationMatchReview::STATUS_LINKED => 'Linked',
                            FdaOrganizationMatchReview::STATUS_REJECTED => 'Rejected',
                            FdaOrganizationMatchReview::STATUS_CREATED_NEW => 'Created New',
                            default => (string) ($state ?? '—'),
                        }),
                    TextEntry::make('source'),
                    TextEntry::make('original_name'),
                    TextEntry::make('canonical_name')->placeholder('—'),
                    FdaRegistryBadges::identifierEntry('duns_number', 'DUNS'),
                    TextEntry::make('confidence')->placeholder('—'),
                    TextEntry::make('proposedOrganization.name')
                        ->label('Proposed organization')
                        ->url(fn (FdaOrganizationMatchReview $record): ?string => $record->proposed_fda_organization_id
                            ? FdaOrganizationResource::getUrl('view', ['record' => $record->proposed_fda_organization_id])
                            : null),
                    TextEntry::make('resolvedOrganization.name')
                        ->label('Resolved organization')
                        ->url(fn (FdaOrganizationMatchReview $record): ?string => $record->resolved_fda_organization_id
                            ? FdaOrganizationResource::getUrl('view', ['record' => $record->resolved_fda_organization_id])
                            : null),
                    TextEntry::make('resolved_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
            Section::make('Incoming payload')
                ->schema([
                    TextEntry::make('payload_json')
                        ->hiddenLabel()
                        ->state(fn (FdaOrganizationMatchReview $record): string => $record->payload_json
                            ? collect($record->payload_json)
                                ->map(fn ($value, $key): string => $key.': '.(is_scalar($value) || $value === null ? (string) ($value ?? '—') : json_encode($value)))
                                ->implode("\n")
                            : '—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
