<?php

declare(strict_types=1);

namespace App\Enums;

enum DataExportType: string
{
    case TrackAndTrace = 'track_and_trace';

    public function label(): string
    {
        return match ($this) {
            self::TrackAndTrace => 'Track and trace',
        };
    }
}
