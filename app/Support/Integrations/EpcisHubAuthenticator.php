<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Illuminate\Http\Request;
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

        if (! is_string($provided) || $provided === '' || ! hash_equals($configured, $provided)) {
            throw new UnauthorizedHttpException('', 'Invalid EPCIS hub token.');
        }

        return $environment;
    }
}
