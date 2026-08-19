<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Validation;

/**
 * Tenant-agnostic candidate document for Domain hard-gate validation.
 *
 * @phpstan-type EventShape array{
 *     event_type?: string,
 *     action?: string,
 *     epc_list?: list<string>,
 *     parent_id?: ?string,
 *     child_epcs?: list<string>,
 *     biz_step?: string,
 *     disposition?: string,
 *     event_time?: string,
 *     xml_well_formed?: bool
 * }
 */
final readonly class ValidationContext
{
    /**
     * @param  list<EventShape>  $events
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public array $events,
        public array $attributes = [],
    ) {}
}
