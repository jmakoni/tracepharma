<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Models\InboundConnection;
use App\Services\Integrations\InboundConnectionLogger;
use Illuminate\Http\Request;

class InboundWebhookAuthenticator
{
    public function __construct(
        private readonly InboundConnectionLogger $logger,
    ) {}

    public function authorize(Request $request, InboundConnection $connection): void
    {
        $token = $connection->credentials['webhook_token'] ?? $connection->inbound_token;
        $providedToken = $request->header('X-Inbound-Token');

        if ($providedToken && hash_equals((string) $token, (string) $providedToken)) {
            return;
        }

        $secret = $connection->credentials['webhook_secret'] ?? null;

        if ($secret) {
            $signature = $request->header('X-Inbound-Signature');
            $expected = hash_hmac('sha256', $request->getContent(), $secret);

            if ($signature && hash_equals($expected, $signature)) {
                return;
            }
        }

        $this->logger->log($connection, 'auth', 'failed', 'Invalid inbound webhook credentials.');

        abort(401, 'Invalid inbound webhook credentials.');
    }
}
