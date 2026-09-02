<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationRequestTrigger: string
{
    case VrsUnavailable = 'vrs_unavailable';
    case VrsError = 'vrs_error';
    case VrsDeferred = 'vrs_deferred';
    case VrsFailed = 'vrs_failed';
    case VrsSuspect = 'vrs_suspect';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::VrsUnavailable => 'VRS unavailable',
            self::VrsError => 'VRS error',
            self::VrsDeferred => 'VRS deferred',
            self::VrsFailed => 'VRS failed',
            self::VrsSuspect => 'VRS suspect',
            self::Manual => 'Manual request',
        };
    }

    public static function fromVerificationStatus(string $status): self
    {
        return match ($status) {
            'unavailable' => self::VrsUnavailable,
            'error' => self::VrsError,
            'deferred' => self::VrsDeferred,
            'failed' => self::VrsFailed,
            'suspect' => self::VrsSuspect,
            default => self::Manual,
        };
    }
}
