<?php

namespace App\Enums;

enum SsccLabelBatchStatus: string
{
    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Generating => 'Generating',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
