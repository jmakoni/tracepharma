<?php

namespace App\Enums;

enum SsccLabelPrintStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Printed = 'printed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Printed => 'Printed',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }
}
