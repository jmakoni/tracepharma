<?php

namespace App\Support\Fda;

final class OrganizationMatch
{
    public const ACTION_LINK = 'link';

    public const ACTION_REVIEW = 'review';

    public const ACTION_CREATE = 'create';

    public function __construct(
        public readonly string $action,
        public readonly ?int $fdaOrganizationId = null,
        public readonly ?float $confidence = null,
        public readonly ?string $reason = null,
    ) {}
}
