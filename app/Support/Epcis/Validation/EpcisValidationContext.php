<?php

namespace App\Support\Epcis\Validation;

use App\Models\Epcis\EpcisDocument;

/**
 * Immutable snapshot of how a single EPCIS document should be validated:
 * which profile is being enforced as "hard", the tenant's configured
 * default, and whether GS1 US R1.3-only rules are active.
 */
final class EpcisValidationContext
{
    public function __construct(
        public readonly EpcisDocument $document,
        public readonly string $direction,
        public readonly EpcisValidationProfile $profile,
        public readonly EpcisValidationProfile $tenantDefault,
        public readonly bool $r13Hard,
        public readonly ?string $payloadPath = null,
        public readonly ?string $declaredGuidelineVersion = null,
    ) {}
}
