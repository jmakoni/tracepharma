<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Receiving\ReceivingSession;

/**
 * Parent/child progress display for receive HUDs (desktop, floor, Scan In).
 *
 * Uses session hierarchy counters — not confirmed scan-line row counts.
 */
final class ReceivingSessionProgress
{
    public function __construct(
        private readonly ReceivingSession $session,
        private readonly ReceivingPolicy $policy,
        private readonly int $stagedParentCount = 0,
        private readonly int $stagedChildCount = 0,
        private readonly ?int $focusChildConfirmed = null,
        private readonly ?int $focusChildExpected = null,
        private readonly ?string $focusChildUom = null,
    ) {}

    public static function for(
        ReceivingSession $session,
        ?ReceivingPolicy $policy = null,
        int $stagedParentCount = 0,
        int $stagedChildCount = 0,
        ?int $focusChildConfirmed = null,
        ?int $focusChildExpected = null,
        ?string $focusChildUom = null,
    ): self {
        return new self(
            $session,
            $policy ?? ReceivingPolicy::forTenant(tenant()),
            $stagedParentCount,
            $stagedChildCount,
            $focusChildConfirmed,
            $focusChildExpected,
            $focusChildUom,
        );
    }

    /**
     * Whether child-level progress is meaningful for this session.
     */
    public function showUnitsProgress(): bool
    {
        return ! ($this->session->isTransferReceive() && (int) $this->session->expected_child_count === 0);
    }

    /**
     * Parent hierarchy type from preferred scan level (transfer → Lines).
     */
    public function parentTypeLabel(): string
    {
        if ($this->session->isTransferReceive()) {
            return 'Lines';
        }

        return match ($this->policy->preferredScanLevel()) {
            ReceivingScanLevel::Pallet => 'Pallets',
            ReceivingScanLevel::Case, ReceivingScanLevel::ToteOrCase => 'Cases',
        };
    }

    /**
     * Child hierarchy type under the preferred scan level.
     */
    public function childTypeLabel(): string
    {
        if ($this->session->isTransferReceive()) {
            return 'Units';
        }

        return match ($this->policy->preferredScanLevel()) {
            ReceivingScanLevel::Pallet => 'Cases',
            ReceivingScanLevel::Case, ReceivingScanLevel::ToteOrCase => 'Units',
        };
    }

    /**
     * Quantity fragment: scan-first "4"; ASN/file "2/7".
     * Optional staged offsets apply on floor before Confirm staged.
     */
    public function parentProgressQuantity(): string
    {
        $confirmed = (int) $this->session->confirmed_parent_count + $this->stagedParentCount;

        if ($this->session->isScanFirst()) {
            return (string) $confirmed;
        }

        return $confirmed.'/'.(int) $this->session->expected_parent_count;
    }

    /**
     * Quantity fragment for children: scan-first "256"; ASN/file "512/354".
     * After a parent confirm, optional focus counters show that parent's N/M.
     */
    public function childProgressQuantity(): string
    {
        if ($this->focusChildExpected !== null && $this->focusChildExpected > 0) {
            return ((int) ($this->focusChildConfirmed ?? 0)).'/'.$this->focusChildExpected;
        }

        $confirmed = (int) $this->session->confirmed_child_count + $this->stagedChildCount;

        if ($this->session->isScanFirst()) {
            return (string) $confirmed;
        }

        return $confirmed.'/'.(int) $this->session->expected_child_count;
    }

    /**
     * Parent chip, e.g. "4 Pallets" or "2/7 Pallets".
     */
    public function parentProgressChipLabel(): string
    {
        return $this->parentProgressQuantity().' '.$this->parentTypeLabel();
    }

    /**
     * Child chip, e.g. "256 Cases" or "1002/5844 Units".
     */
    public function childProgressChipLabel(): string
    {
        $uom = filled($this->focusChildUom) ? (string) $this->focusChildUom : $this->childTypeLabel();

        return $this->childProgressQuantity().' '.$uom;
    }

    /**
     * Combined label for aria / tests.
     */
    public function chipLabel(): string
    {
        if (! $this->showUnitsProgress()) {
            return $this->parentProgressChipLabel();
        }

        return $this->parentProgressChipLabel().' · '.$this->childProgressChipLabel();
    }

    public function ariaLabel(): string
    {
        return $this->chipLabel();
    }
}
