<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * The organization's GS1 identity collides with an active trading partner's.
 *
 * Carries the offending field so callers can put the message on the input the
 * operator has to fix instead of failing the whole request.
 */
final class OrganizationIdentityConflictException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $field,
    ) {
        parent::__construct($message);
    }
}
