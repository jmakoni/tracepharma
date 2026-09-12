<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OutboundShippingSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Session')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('site.name')
                        ->label('Ship from'),
                    TextEntry::make('tradingPartner.name')
                        ->label('Customer')
                        ->placeholder('—'),
                    TextEntry::make('asn_number')
                        ->label('ASN')
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('customer_po')
                        ->label('PO')
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('invoice_number')
                        ->label('Invoice')
                        ->copyable()
                        ->placeholder('—'),
                    IconEntry::make('dscsa_affirm')
                        ->label('TI/TS affirmed')
                        ->boolean(),
                    IconEntry::make('is_drop_shipment')
                        ->label('Drop shipment')
                        ->boolean(),
                    IconEntry::make('is_corrective')
                        ->label('Corrective')
                        ->boolean(),
                    TextEntry::make('corrective_reason')
                        ->label('Correction reason')
                        ->visible(fn ($record): bool => (bool) $record?->is_corrective)
                        ->columnSpanFull(),
                    TextEntry::make('confirmed_count')
                        ->label('Confirmed units'),
                    TextEntry::make('expected_count')
                        ->label('Expected units')
                        ->visible(fn ($record): bool => (int) ($record?->expected_count ?? 0) > 0),
                    IconEntry::make('split_declared')
                        ->label('Split declared')
                        ->boolean()
                        ->visible(fn ($record): bool => (bool) $record?->split_declared),
                    TextEntry::make('opened_at')
                        ->dateTime(),
                    TextEntry::make('completed_at')
                        ->dateTime()
                        ->placeholder('—'),
                ]),
        ]);
    }
}
