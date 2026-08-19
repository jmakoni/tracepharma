<?php

namespace App\Enums;

enum SsccPrintJobStatus: string
{
    case Queued = 'queued';
    case Printing = 'printing';
    case Printed = 'printed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Printing => 'Printing',
            self::Printed => 'Printed',
            self::Failed => 'Failed',
        };
    }
}
