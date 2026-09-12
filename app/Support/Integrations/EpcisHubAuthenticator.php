<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class EpcisHubAuthenticator
{
    public function __construct(
        private readonly EpcisHubPlatformConfig $platformConfig,
    ) {}

    /**
     * Authorize the hub request and return the resolved environment (demo|stage|prod).
     */
    public function authorize(Request $request): string
    {
        $environment = $this->platformConfig->environmentForHost($request->getHost());

        if ($environment === null) {
            throw new UnauthorizedHttpException('', 'Unknown EPCIS hub host.');
        }

        $configured = $this->platformConfig->hubToken($environment);

        if (! is_string($configured) || $configured === '') {
            throw new UnauthorizedHttpException('', 'EPCIS hub authentication is not configured.');
        }

        $provided = $request->header('X-Epcis-Hub-Token');

        if (! is_string($provided) || $provided === '') {
            $provided = $request->header('X-Inbound-Token');
        }

        if (! is_string($provided) || $provided === '') {
            throw new UnauthorizedHttpException('', 'Invalid EPCIS hub token.');
        }

        if (hash_equals($configured, $provided)) {
            return $environment;
        }

        // Zero-downtime rotation: the previous token stays accepted inside its
        // grace window so partners can cut over without dropped deliveries.
        $previous = $this->platformConfig->previousHubToken($environment);

        if (is_string($previous) && $previous !== '' && hash_equals($previous, $provided)) {
            Log::info('EPCIS hub request authenticated with previous (rotating) token.', [
                'environment' => $environment,
                'grace_expires_at' => $this->platformConfig->previousHubTokenExpiresAt($environment)?->toIso8601String(),
            ]);

            return $environment;
        }

        throw new UnauthorizedHttpException('', 'Invalid EPCIS hub token.');
    }
}
