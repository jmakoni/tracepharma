<?php

namespace App\Services\Tracing;

use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestStatus;
use App\Models\TracingRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class TracingSlaService
{
    public function slaHoursFor(TracingRequestorType|string|null $requestorType = null): int
    {
        $type = $requestorType instanceof TracingRequestorType
            ? $requestorType
            : TracingRequestorType::tryFrom((string) $requestorType);

        return match ($type) {
            TracingRequestorType::Regulator => max(1, (int) config('tracing.regulator_sla_hours', 24)),
            TracingRequestorType::Supplier => max(1, (int) config('tracing.supplier_sla_hours', 48)),
            default => max(1, (int) config('tracing.sla_hours', 24)),
        };
    }

    public function computeDueAt(
        ?CarbonInterface $requestedAt = null,
        TracingRequestorType|string|null $requestorType = null,
    ): CarbonInterface {
        $base = ($requestedAt ?? now())->copy();

        return $base->addHours($this->slaHoursFor($requestorType));
    }

    public function applySlaClock(TracingRequest $request, ?TracingRequestorType $requestorType = null): TracingRequest
    {
        $requestorType ??= $request->requestor_type ?? TracingRequestorType::Internal;
        $requestedAt = $request->requested_at ?? now();

        $request->update([
            'requestor_type' => $requestorType,
            'requested_at' => $requestedAt,
            'due_at' => $this->computeDueAt($requestedAt, $requestorType),
        ]);

        return $request->fresh();
    }

    public function markResponded(TracingRequest $request, array $responseMetadata = []): TracingRequest
    {
        $payload = [
            'response_metadata' => array_merge($request->response_metadata ?? [], $responseMetadata),
        ];

        if ($request->responded_at === null) {
            $payload['responded_at'] = now();
            $payload['sla_breached'] = $request->due_at !== null && now()->gt($request->due_at);
        }

        $request->update($payload);

        return $request->fresh();
    }

    /**
     * Open tracing requests whose SLA clock has elapsed.
     *
     * @return Collection<int, TracingRequest>
     */
    public function findOverdue(): Collection
    {
        return TracingRequest::query()
            ->whereNull('responded_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [
                TracingRequestStatus::Completed->value,
                TracingRequestStatus::Cancelled->value,
            ])
            ->orderBy('due_at')
            ->get();
    }

    public function flagBreached(TracingRequest $request): TracingRequest
    {
        if ($request->responded_at !== null) {
            return $request;
        }

        $request->update(['sla_breached' => true]);

        return $request->fresh();
    }
}
