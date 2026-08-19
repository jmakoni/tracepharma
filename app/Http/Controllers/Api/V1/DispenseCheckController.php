<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Vrs\RunProductVerification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DispenseCheckRequest;
use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final class DispenseCheckController extends Controller
{
    public function __invoke(
        DispenseCheckRequest $request,
        RunProductVerification $verification,
        ResolveEpcFromScan $resolveEpcFromScan,
        ReceivingGate $receivingGate,
    ): JsonResponse {
        if (! TenantFeatures::forTenant(tenant())->supportsVrs()) {
            abort(403, 'VRS is not enabled for this tenant profile.');
        }

        $scan = $this->resolveScan($request);

        try {
            $result = $verification->handle($scan, $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $record = $result['verification'];
        $allowed = $record->status === 'verified';
        $message = $record->message;
        $status = $record->status;
        $exceptionId = $result['exception_id'];

        if ($allowed) {
            $quarantineBlock = $this->quarantineBlockForScan($scan, $resolveEpcFromScan, $receivingGate);
            if ($quarantineBlock !== null) {
                $allowed = false;
                $status = 'quarantined';
                $message = $quarantineBlock['message'];
                $exceptionId = $quarantineBlock['exception_id'];
            }
        }

        $visibleExceptionId = $this->visibleExceptionId($request->user(), $exceptionId);

        return response()->json([
            'allowed' => $allowed,
            'status' => $status,
            'message' => $message,
            'verification_id' => $record->getKey(),
            ...($visibleExceptionId !== null ? ['exception_id' => $visibleExceptionId] : []),
        ]);
    }

    private function visibleExceptionId(?User $user, mixed $exceptionId): ?int
    {
        if ($exceptionId === null) {
            return null;
        }

        $caseId = (int) $exceptionId;
        if ($caseId <= 0) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $caseId;
        }

        $case = ExceptionCase::query()->find($caseId);
        if ($case === null || $case->site_id === null) {
            return null;
        }

        return SiteAccess::canAccessSite($user, (int) $case->site_id) ? $caseId : null;
    }

    /**
     * @return array{message: string, exception_id: ?int}|null
     */
    private function quarantineBlockForScan(
        string $scan,
        ResolveEpcFromScan $resolveEpcFromScan,
        ReceivingGate $receivingGate,
    ): ?array {
        $epc = $resolveEpcFromScan->handle($scan)['epc'];
        if ($epc === null) {
            return null;
        }

        $hold = $receivingGate->epcBlockedByOpenHold($epc);
        if ($hold === null) {
            return null;
        }

        $caseId = $hold->exception_id;
        $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

        return [
            'message' => 'Under quarantine'.$suffix.'. Clear or release quarantine before dispensing.',
            'exception_id' => $caseId !== null ? (int) $caseId : null,
        ];
    }

    private function resolveScan(DispenseCheckRequest $request): string
    {
        if ($request->filled('barcode')) {
            return (string) $request->input('barcode');
        }

        $gtin = $request->input('gtin14') ?? $request->input('gtin');
        $gtin14 = str_pad(preg_replace('/\D+/', '', (string) $gtin) ?? '', 14, '0', STR_PAD_LEFT);
        $serial = trim((string) $request->input('serial'));
        $scan = '(01)'.$gtin14.'(21)'.$serial;

        if ($request->filled('lot')) {
            $scan .= '(10)'.trim((string) $request->input('lot'));
        }

        if ($request->filled('expiry')) {
            $expiry = preg_replace('/\D+/', '', (string) $request->input('expiry')) ?? '';

            if (strlen($expiry) === 8) {
                $expiry = substr($expiry, 2);
            }

            if (strlen($expiry) === 6) {
                $scan .= '(17)'.$expiry;
            }
        }

        return $scan;
    }
}
