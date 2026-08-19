<?php

namespace App\Enums;

enum ExceptionStatus: string
{
    case New = 'new';
    case Triaged = 'triaged';
    case Investigating = 'investigating';
    case WaitingInternal = 'waiting_internal';
    case WaitingPartner = 'waiting_partner';
    case PendingApproval = 'pending_approval';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Triaged => 'Triaged',
            self::Investigating => 'Investigating',
            self::WaitingInternal => 'Waiting (internal)',
            self::WaitingPartner => 'Waiting (partner)',
            self::PendingApproval => 'Pending approval',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::New => 'danger',
            self::Triaged, self::Investigating => 'warning',
            self::WaitingInternal, self::WaitingPartner, self::PendingApproval => 'info',
            self::Resolved => 'success',
            self::Closed, self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed, self::Cancelled], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Triaged, self::Cancelled],
            self::Triaged => [self::Investigating, self::WaitingInternal, self::WaitingPartner, self::Cancelled],
            self::Investigating => [
                self::WaitingInternal,
                self::WaitingPartner,
                self::PendingApproval,
                self::Resolved,
                self::Cancelled,
            ],
            self::WaitingInternal, self::WaitingPartner => [
                self::Investigating,
                self::PendingApproval,
                self::Resolved,
            ],
            self::PendingApproval => [self::Resolved, self::Investigating],
            self::Resolved => [self::Closed, self::Investigating],
            self::Closed, self::Cancelled => [],
        };
    }

    public function allowsTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
