<?php

namespace App\Enums;

enum ExceptionTypeCategory: string
{
    case Identifier = 'identifier';
    case MasterData = 'master_data';
    case EventStructure = 'event_structure';
    case Aggregation = 'aggregation';
    case Quantity = 'quantity';
    case Timing = 'timing';
    case Transmission = 'transmission';
    case Process = 'process';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Identifier => 'Identifier',
            self::MasterData => 'Master data',
            self::EventStructure => 'Event structure',
            self::Aggregation => 'Aggregation',
            self::Quantity => 'Quantity / lot / expiry',
            self::Timing => 'Timing & sequence',
            self::Transmission => 'Transmission & partner',
            self::Process => 'Process & DSCSA',
            self::System => 'System / operational',
        };
    }
}
