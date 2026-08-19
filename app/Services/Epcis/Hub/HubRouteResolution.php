<?php

declare(strict_types=1);

namespace App\Services\Epcis\Hub;

use App\Models\InboundConnection;
use App\Models\Tenant;

readonly class HubRouteResolution
{
    private function __construct(
        public string $type,
        public ?Tenant $tenant = null,
        public ?InboundConnection $connection = null,
        public ?string $receiverGln = null,
        public ?string $senderGln = null,
        public ?string $message = null,
    ) {}

    public static function probe(): self
    {
        return new self(type: 'probe');
    }

    public static function routed(
        Tenant $tenant,
        InboundConnection $connection,
        ?string $receiverGln,
        ?string $senderGln,
    ): self {
        return new self(
            type: 'routed',
            tenant: $tenant,
            connection: $connection,
            receiverGln: $receiverGln,
            senderGln: $senderGln,
        );
    }

    public function isProbe(): bool
    {
        return $this->type === 'probe';
    }

    public function isRouted(): bool
    {
        return $this->type === 'routed';
    }
}
