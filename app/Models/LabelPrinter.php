<?php

namespace App\Models;

use App\Enums\LabelPrinterProtocol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabelPrinter extends Model
{
    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'protocol',
        'is_default',
        'enabled',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'protocol' => LabelPrinterProtocol::class,
            'is_default' => 'boolean',
            'enabled' => 'boolean',
            'settings' => 'array',
            'port' => 'integer',
        ];
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(SsccPrintJob::class);
    }

    public function protocolOrDefault(): LabelPrinterProtocol
    {
        return $this->protocol ?? LabelPrinterProtocol::ZplRaw;
    }

    /**
     * OS / QZ / Browser Print printer name used by client bridges.
     */
    public function clientPrinterName(): string
    {
        $fromSettings = data_get($this->settings ?? [], 'client_printer_name');

        if (is_string($fromSettings) && trim($fromSettings) !== '') {
            return trim($fromSettings);
        }

        return (string) $this->name;
    }

    public function displayName(): string
    {
        $protocol = $this->protocolOrDefault();

        if ($protocol->isClientSide()) {
            return "{$this->name} ({$protocol->label()}: {$this->clientPrinterName()})";
        }

        $host = $this->ip_address ?? '—';
        $port = $this->port ?? $protocol->defaultPort();

        return "{$this->name} ({$host}:{$port})";
    }
}
