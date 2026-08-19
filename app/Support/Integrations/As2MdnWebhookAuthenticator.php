<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Models\OutboundConnection;
use Illuminate\Http\Request;

class As2MdnWebhookAuthenticator
{
    public function authorize(Request $request, OutboundConnection $connection): void
    {
        $secret = $connection->credentials['as2_mdn_webhook_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            abort(401, 'Invalid AS2 MDN webhook credentials.');
        }

        $providedSecret = $request->header('X-As2-Mdn-Secret');

        if (is_string($providedSecret) && hash_equals($secret, $providedSecret)) {
            return;
        }

        $authorization = $request->header('Authorization');

        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            $bearer = substr($authorization, 7);

            if (hash_equals($secret, $bearer)) {
                return;
            }
        }

        abort(401, 'Invalid AS2 MDN webhook credentials.');
    }
}
