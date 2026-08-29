<?php

namespace App\Enums;

/**
 * Operator-selected decommission reason → CBV disposition local name.
 */
enum DecommissionReason: string
{
    case Destroyed = 'destroyed';
    case Expired = 'expired';
    case Recalled = 'recalled';
    case Returned = 'returned';
    case SuspectIllegitimate = 'suspect_illegitimate';
    case QaRejectNeverShipped = 'qa_reject_never_shipped';

    public function label(): string
    {
        return match ($this) {
            self::Destroyed => 'Destroyed',
            self::Expired => 'Expired',
            self::Recalled => 'Recalled',
            self::Returned => 'Returned',
            self::SuspectIllegitimate => 'Suspect / illegitimate',
            self::QaRejectNeverShipped => 'QA reject / never shipped',
        };
    }

    /**
     * CBV disposition local name (urn:epcglobal:cbv:disp:{local}).
     */
    public function dispositionLocal(): string
    {
        return match ($this) {
            self::Destroyed => 'destroyed',
            self::Expired => 'expired',
            self::Recalled => 'recalled',
            self::Returned => 'returned',
            self::SuspectIllegitimate => 'inactive',
            self::QaRejectNeverShipped => 'disposed',
        };
    }

    public function dispositionUri(): string
    {
        return 'urn:epcglobal:cbv:disp:'.$this->dispositionLocal();
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
