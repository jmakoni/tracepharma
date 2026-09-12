<?php

namespace App\Rules;

use App\Support\TenantSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a partner GLN issued under this organization's GS1 Company Prefix unless
 * {@see TenantSettings::allowAssignPartnerGlnsFromPrefix()} is enabled.
 *
 * Exact organization GLNs remain blocked by {@see RejectTenantGln}.
 */
final class RejectPartnerGlnUnderOrgPrefix implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return;
        }

        $settings = TenantSettings::forTenant(tenant());

        if ($settings->allowAssignPartnerGlnsFromPrefix()) {
            return;
        }

        if (! $settings->glnIsUnderCompanyPrefix((string) $value)) {
            return;
        }

        $fail(
            'The :attribute is issued under your organization GS1 Company Prefix. '
            .'Record the partner\'s own GLN from their EPCIS, or enable '
            .'"Allow assign partner GLNs from our prefix" in Organization settings.',
        );
    }
}
