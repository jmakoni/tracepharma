<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Epcis\EpcisDocument;
use App\Services\Epcis\Outbound\CanonicalEventsToJsonLd20;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Short-TTL signed download of query-as-2.0 JSON-LD for subscription webhooks.
 */
final class EpcisSubscriptionDownloadController extends Controller
{
    public function __invoke(Request $request, EpcisDocument $document, CanonicalEventsToJsonLd20 $projector): JsonResponse
    {
        try {
            $json = $projector->projectDocument($document);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            abort(500, 'Unable to encode EPCIS 2.0 JSON-LD projection.');
        }

        return response()->json($decoded, 200, [
            'Content-Type' => 'application/ld+json; charset=UTF-8',
        ]);
    }
}
