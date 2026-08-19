<?php

namespace App\Enums;

enum Fda3911ReportStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready to submit',
            self::Submitted => 'Submitted to FDA',
            self::Acknowledged => 'Acknowledged (incident # received)',
            self::Terminated => 'Terminated',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ready => 'info',
            self::Submitted => 'warning',
            self::Acknowledged => 'success',
            self::Terminated => 'gray',
        };
    }
}
