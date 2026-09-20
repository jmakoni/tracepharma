<?php

declare(strict_types=1);

namespace App\Enums;

enum EpcisGuideline: string
{
    case R12 = 'r12';
    case R13 = 'r13';

    public function label(): string
    {
        return match ($this) {
            self::R12 => 'GS1 US DSCSA R1.2',
            self::R13 => 'GS1 US DSCSA R1.3',
        };
    }

    public function sbdhAuthority(): string
    {
        return match ($this) {
            self::R12 => 'GLN',
            self::R13 => 'GS1',
        };
    }
}
