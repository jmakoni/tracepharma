<?php

namespace App\Services\Tracing;

use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Models\TracingRequest;
use App\Models\User;
use InvalidArgumentException;

class TracingRequestService
{
    public function __construct(
        private readonly TracingSlaService $sla,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): TracingRequest
    {
        $requestorType = $attributes['requestor_type'] ?? TracingRequestorType::Internal;
        if (! $requestorType instanceof TracingRequestorType) {
            $requestorType = TracingRequestorType::tryFrom((string) $requestorType)
                ?? TracingRequestorType::Internal;
        }

        $scope = $attributes['scope'] ?? TracingRequestScope::SingleProduct;
        if (! $scope instanceof TracingRequestScope) {
            $scope = TracingRequestScope::tryFrom((string) $scope)
                ?? TracingRequestScope::SingleProduct;
        }

        $requestedAt = $attributes['requested_at'] ?? now();

        $request = TracingRequest::query()->create([
            'title' => $attributes['title'],
            'status' => TracingRequestStatus::Open,
            'requestor_type' => $requestorType,
            'requested_by' => $actor?->id ?? ($attributes['requested_by'] ?? null),
            'exception_id' => $attributes['exception_id'] ?? null,
            'gtin' => $attributes['gtin'] ?? null,
            'serial' => $attributes['serial'] ?? null,
            'lot' => $attributes['lot'] ?? null,
            'expiry' => $attributes['expiry'] ?? null,
            'scope' => $scope,
            'is_recall' => (bool) ($attributes['is_recall'] ?? false),
            'notes' => $attributes['notes'] ?? null,
            'requested_at' => $requestedAt,
            'due_at' => $this->sla->computeDueAt($requestedAt, $requestorType),
            'sla_breached' => false,
        ]);

        return $request->fresh();
    }

    public function transition(TracingRequest $request, TracingRequestStatus $next): TracingRequest
    {
        if (! $request->status->canTransitionTo($next)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot transition tracing request from %s to %s.',
                $request->status->value,
                $next->value,
            ));
        }

        $payload = ['status' => $next];

        if ($next === TracingRequestStatus::Completed) {
            if ($request->responded_at === null) {
                throw new InvalidArgumentException(
                    'Cannot complete tracing request without a recorded response — use Record response first.',
                );
            }

            $payload['completed_at'] = now();
            $payload['sla_breached'] = $request->due_at !== null && $request->responded_at->gt($request->due_at);
        }

        $request->update($payload);

        return $request->fresh();
    }

    public function start(TracingRequest $request): TracingRequest
    {
        return $this->transition($request, TracingRequestStatus::InProgress);
    }

    public function complete(TracingRequest $request): TracingRequest
    {
        return $this->transition($request, TracingRequestStatus::Completed);
    }

    public function cancel(TracingRequest $request): TracingRequest
    {
        return $this->transition($request, TracingRequestStatus::Cancelled);
    }
}
