<?php

namespace App\Enums;

enum Fda3911Classification: string
{
    case Illegitimate = 'illegitimate';
    case HighRisk = 'high_risk';

    public function label(): string
    {
        return match ($this) {
            self::Illegitimate => 'Illegitimate product',
            self::HighRisk => 'High risk of illegitimacy',
        };
    }
}
