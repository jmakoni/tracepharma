<?php

declare(strict_types=1);

namespace App\Enums\Portal;

enum PortalShipmentExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Json = 'json';
    case Xml = 'xml';
    case Pdf = 'pdf';

    public static function tryFromRequest(mixed $value): ?self
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower($value));
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Json => 'application/json; charset=UTF-8',
            self::Xml => 'application/xml; charset=UTF-8',
            self::Pdf => 'application/pdf',
        };
    }
}
