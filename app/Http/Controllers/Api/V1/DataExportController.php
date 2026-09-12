<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DataExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DataExportResource;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DataExportController extends Controller
{
    public function show(Request $request, DataExport $export): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $this->authorizeExport($user, $export);

        if ($export->status === DataExportStatus::Completed && $export->isExpired()) {
            return response()->json([
                'message' => 'This export has expired.',
                'export_id' => (string) $export->getKey(),
                'status' => $export->status?->value,
                'expires_at' => $export->expires_at?->toIso8601String(),
            ], 410);
        }

        return (new DataExportResource($export))->response();
    }

    private function authorizeExport(User $user, DataExport $export): void
    {
        abort_if($export->requested_by_user_id === null, 403);

        abort_unless((int) $export->requested_by_user_id === (int) $user->getKey(), 403);
    }
}
