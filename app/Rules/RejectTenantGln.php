<?php

namespace App\Rules;

use App\Support\Custody\TenantGlnSet;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a GLN that already identifies this organization — its own GLN or one of its
 * facilities ({@see TenantGlnSet}).
 *
 * We are never our own trading partner: a self-partner collides with organization
 * facilities on the UNIQUE sites.gln index, breaks SSCC identity checks and makes EPCIS
 * custody read as if goods left the building. EPCIS ingest already skips these GLNs;
 * this rule closes the manual entry path. Pair it with the trading partner form and
 * partner-owned site forms only — an organization facility carrying one of these GLNs is
 * exactly right.
 *
 * Blank passes; pair with `required` when the field is mandatory.
 */
final class RejectTenantGln implements ValidationRule
{
    public function __construct(private readonly ?TenantGlnSet $tenantGlns = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return;
        }

        if (! ($this->tenantGlns ?? new TenantGlnSet)->contains((string) $value)) {
            return;
        }

        $fail('The :attribute is one of your own GLNs. Record your organization\'s locations as sites, not as a trading partner.');
    }
}
