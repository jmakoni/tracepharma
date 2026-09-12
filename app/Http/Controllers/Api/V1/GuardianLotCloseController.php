<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\L3\ReceiveGuardianLotFeed;
use App\Exceptions\GuardianLotCloseConflictException;
use App\Exceptions\GuardianLotCloseDisabledException;
use App\Exceptions\GuardianLotCloseUnauthorizedException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\InitializeTenancyForTenantHosts;
use App\Support\Tenancy\TenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/l3/guardian/lot-close — Guardian (Systech) lot-close inbound.
 *
 * Raw `DataFeed` XML body, `Authorization: Bearer {l3.api_key}`. Tenant is
 * already resolved from the request host by
 * {@see InitializeTenancyForTenantHosts}.
 */
final class GuardianLotCloseController extends Controller
{
    public function __construct(
        private readonly ReceiveGuardianLotFeed $receive,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        TenantAccess::assertActive();

        $maxBytes = (int) config('tracepharma.guardian_lot_close.max_upload_mb', 50) * 1024 * 1024;
        $contentLength = $request->header('Content-Length');
        if (is_numeric($contentLength) && (int) $contentLength > $maxBytes) {
            return response()->json(['message' => 'Guardian DataFeed payload exceeds the configured size limit.'], 413);
        }

        $body = (string) $request->getContent();

        if (strlen($body) > $maxBytes) {
            return response()->json(['message' => 'Guardian DataFeed payload exceeds the configured size limit.'], 413);
        }

        if (stripos($body, '<!DOCTYPE') !== false) {
            return response()->json(['message' => 'Guardian DataFeed must not include a DOCTYPE declaration.'], 422);
        }

        if (trim($body) === '' || stripos($body, '<') === false) {
            return response()->json(['message' => 'Request body must be XML.'], 422);
        }

        try {
            $feed = $this->receive->handle($body, $request->bearerToken());
        } catch (GuardianLotCloseUnauthorizedException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        } catch (GuardianLotCloseDisabledException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (GuardianLotCloseConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'feed_id' => $feed->getKey(),
            'message_id' => $feed->message_id,
            'status' => $feed->status,
        ], 202);
    }
}
