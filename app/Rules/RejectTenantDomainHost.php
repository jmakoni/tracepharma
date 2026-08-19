<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class RejectTenantDomainHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value)) {
            return;
        }

        try {
            EpcisHubPlatformConfig::assertHubHostAllowed($value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
