<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Exports\QueueTrackTraceExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TrackTraceExportRequest;
use App\Http\Resources\Api\V1\DataExportResource;
use App\Models\User;
use App\Support\TenantFeatures;
use Illuminate\Http\JsonResponse;

final class TrackTraceExportController extends Controller
{
    public function __construct(
        private readonly QueueTrackTraceExport $queueExport,
    ) {}

    public function store(TrackTraceExportRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless(TenantFeatures::forTenant(tenant())->supportsTrackAndTraceExport(), 403);

        $export = $this->queueExport->handle($user, $request->validated());

        return (new DataExportResource($export))
            ->response()
            ->setStatusCode(202);
    }
}
