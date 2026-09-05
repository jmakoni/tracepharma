<?php

namespace App\Support\Shipping;

use InvalidArgumentException;

/**
 * Tenant-issued SSCC failed the outbound ship completeness gate (empty plate or set mismatch).
 */
final class SsccShipCompletenessException extends InvalidArgumentException
{
    /**
     * @param  list<int>  $affectedChildEpcIds
     */
    public function __construct(
        string $message,
        public readonly string $exceptionTypeCode,
        public readonly int $parentEpcId,
        public readonly array $affectedChildEpcIds = [],
    ) {
        parent::__construct($message);
    }

    public function isEmptyPlate(): bool
    {
        return $this->exceptionTypeCode === 'MISSING_CHILDREN';
    }

    /**
     * @return list<int>
     */
    public function epcIdsForCase(): array
    {
        return array_values(array_unique([
            $this->parentEpcId,
            ...$this->affectedChildEpcIds,
        ]));
    }
}
