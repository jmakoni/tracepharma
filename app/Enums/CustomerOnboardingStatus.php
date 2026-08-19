<?php

namespace App\Enums;

enum CustomerOnboardingStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Provisioned = 'provisioned';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Provisioned => 'Provisioned',
            self::Rejected => 'Rejected',
        };
    }

    public function canApprove(): bool
    {
        return $this === self::Submitted;
    }

    public function canReject(): bool
    {
        return $this === self::Submitted;
    }

    public function claimsProdTenant(): bool
    {
        return match ($this) {
            self::Submitted, self::Approved => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function claimingProdTenant(): array
    {
        return [
            self::Submitted,
            self::Approved,
        ];
    }
}
