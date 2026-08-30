<?php

namespace App\Filament\App\Resources\LabelPrinters\Schemas;

use App\Enums\LabelPrinterProtocol;
use App\Services\Labeling\NetworkPrinterClient;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get as SchemaGet;
use Filament\Schemas\Schema;

class LabelPrinterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Printer')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Friendly name shown in print pickers.'),
                        Select::make('protocol')
                            ->options(collect(LabelPrinterProtocol::cases())->mapWithKeys(
                                fn (LabelPrinterProtocol $protocol): array => [$protocol->value => $protocol->label()]
                            ))
                            ->default(LabelPrinterProtocol::ZplRaw->value)
                            ->required()
                            ->live()
                            ->native(false)
                            ->helperText('Network TCP needs a path from the app server to the printer. QZ Tray / Zebra Browser Print print from the workstation (firewall-friendly).'),
                        TextInput::make('ip_address')
                            ->label('IP address or hostname')
                            ->maxLength(255)
                            ->required(fn (SchemaGet $get): bool => self::protocolIsNetwork($get('protocol')))
                            ->visible(fn (SchemaGet $get): bool => self::protocolIsNetwork($get('protocol')))
                            ->rule(function (): \Closure {
                                return function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (! is_string($value) || $value === '') {
                                        return;
                                    }

                                    try {
                                        NetworkPrinterClient::assertSafePrinterHost($value);
                                    } catch (\InvalidArgumentException $exception) {
                                        $fail($exception->getMessage());
                                    }
                                };
                            }),
                        TextInput::make('port')
                            ->numeric()
                            ->default(9100)
                            ->required(fn (SchemaGet $get): bool => self::protocolIsNetwork($get('protocol')))
                            ->visible(fn (SchemaGet $get): bool => self::protocolIsNetwork($get('protocol'))),
                        TextInput::make('settings.client_printer_name')
                            ->label('Workstation printer name')
                            ->maxLength(255)
                            ->helperText(fn (SchemaGet $get): string => self::protocolIsNetwork($get('protocol'))
                                ? 'Required when org default bridge is QZ Tray / Zebra Browser Print.'
                                : 'Exact name as shown in Windows/macOS Printers, or the Zebra Browser Print device name.')
                            ->required(fn (SchemaGet $get): bool => ! self::protocolIsNetwork($get('protocol'))),
                        Toggle::make('is_default')
                            ->label('Default printer'),
                        Toggle::make('enabled')
                            ->default(true),
                    ]),
            ]);
    }

    private static function protocolIsNetwork(mixed $protocol): bool
    {
        $value = $protocol instanceof LabelPrinterProtocol
            ? $protocol
            : LabelPrinterProtocol::tryFrom((string) $protocol);

        return ($value ?? LabelPrinterProtocol::ZplRaw)->requiresNetworkAddress();
    }
}
