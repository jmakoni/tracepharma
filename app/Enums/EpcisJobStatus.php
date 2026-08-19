<?php

declare(strict_types=1);

namespace App\Enums;

enum EpcisJobStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Processing = 'processing';
    case Complete = 'complete';
    case Error = 'error';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Processing => 'Processing',
            self::Complete => 'Complete',
            self::Error => 'Error',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Complete, self::Error, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Sending, self::Processing], true);
    }
}
