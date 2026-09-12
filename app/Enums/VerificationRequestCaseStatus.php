<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationRequestCaseStatus: string
{
    case Pending = 'pending';
    case Responded = 'responded';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending manufacturer',
            self::Responded => 'Responded',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }
}
