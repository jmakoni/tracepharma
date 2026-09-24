<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Enums\EpcisGuideline;

final readonly class DscsaGuidelineDetection
{
    /**
     * @param  list<string>  $r12Signals
     * @param  list<string>  $r13Signals
     */
    public function __construct(
        public bool $mixed,
        public ?EpcisGuideline $release,
        public array $r12Signals = [],
        public array $r13Signals = [],
        public bool $lotOnly = false,
    ) {}

    /**
     * @param  list<string>  $r12Signals
     * @param  list<string>  $r13Signals
     */
    public static function r12(array $r12Signals = [], array $r13Signals = [], bool $lotOnly = false): self
    {
        return new self(false, EpcisGuideline::R12, $r12Signals, $r13Signals, $lotOnly);
    }

    /**
     * @param  list<string>  $r12Signals
     * @param  list<string>  $r13Signals
     */
    public static function r13(array $r12Signals = [], array $r13Signals = []): self
    {
        return new self(false, EpcisGuideline::R13, $r12Signals, $r13Signals);
    }

    /**
     * @param  list<string>  $r12Signals
     * @param  list<string>  $r13Signals
     */
    public static function mixed(array $r12Signals, array $r13Signals): self
    {
        return new self(true, null, $r12Signals, $r13Signals);
    }
}
