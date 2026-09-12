<?php

namespace App\Enums;

enum OutboundConformanceState: string
{
    case Test = 'test';
    case Conformance = 'conformance';
    case FirstLiveLot = 'first_live_lot';
    case Hypercare = 'hypercare';
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Test',
            self::Conformance => 'Conformance',
            self::FirstLiveLot => 'First live lot',
            self::Hypercare => 'Hypercare',
            self::Live => 'Live',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $state): array => [$state->value => $state->label()])
            ->all();
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Test => self::Conformance,
            self::Conformance => self::FirstLiveLot,
            self::FirstLiveLot => self::Hypercare,
            self::Hypercare => self::Live,
            self::Live => null,
        };
    }

    public function canPromoteTo(self $target): bool
    {
        return $this->next() === $target;
    }

    public function isLive(): bool
    {
        return $this === self::Live;
    }

    /**
     * Live ladder connections must supply a positive expected_count before ship complete.
     */
    public function requiresExpectedQuantity(): bool
    {
        return match ($this) {
            self::FirstLiveLot, self::Hypercare, self::Live => true,
            default => false,
        };
    }
}
