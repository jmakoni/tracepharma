<?php

namespace App\Support\MasterData;

use App\Support\Places\UsState;

final class TenantReceivingState
{
    public static function resolve(): ?string
    {
        $raw = tenancy()->tenant?->receiving_state;

        if (blank($raw)) {
            return null;
        }

        return UsState::normalize((string) $raw) ?? strtoupper(trim((string) $raw)) ?: null;
    }
}
