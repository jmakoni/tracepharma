<?php

namespace App\Enums;

/**
 * Receive-oriented disposition for exception types.
 *
 * Post-ingestion investigation taxonomy stays on {@see ExceptionTypeCategory}.
 * This enum answers: does an open case of this type block inbound receiving?
 */
enum ExceptionReceiveImpact: string
{
    /** Regulatory / integrity failures — block receive until cleared. */
    case HardBlocking = 'hard_blocking';

    /** Semantic / assortment / quantity rules — block receive until resolved. */
    case BusinessRule = 'business_rule';

    /** Quality / interoperability issues — surface in UI; do not gate receive. */
    case Warning = 'warning';

    /** Informational / demo-softened flags — never gate receive. */
    case Soft = 'soft';

    public function label(): string
    {
        return match ($this) {
            self::HardBlocking => 'Hard / blocking',
            self::BusinessRule => 'Business rule / semantic',
            self::Warning => 'Warning / quality',
            self::Soft => 'Soft / informational',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::HardBlocking => 'danger',
            self::BusinessRule => 'warning',
            self::Warning => 'info',
            self::Soft => 'gray',
        };
    }

    public function blocksReceiving(): bool
    {
        return $this === self::HardBlocking || $this === self::BusinessRule;
    }
}
