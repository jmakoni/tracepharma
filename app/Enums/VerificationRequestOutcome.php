<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationRequestOutcome: string
{
    case Positive = 'positive';
    case Negative = 'negative';

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positive verification',
            self::Negative => 'Negative verification',
        };
    }
}
