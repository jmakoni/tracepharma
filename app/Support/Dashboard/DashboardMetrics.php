<?php

namespace App\Support\Dashboard;

use App\Enums\TracingRequestStatus;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\TracingRequest;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Integrations\IntegrationHealthMetrics;
use App\Support\TenantFeatures;
use App\Support\Vrs\VerificationScorecardMetrics;
use Illuminate\Support\Carbon;

final class DashboardMetrics
{
    public function __construct(
        private readonly ?User $user,
        private readonly TenantFeatures $features,
    ) {}

    public static function make(?User $user = null): self
    {
        return new self(
            $user ?? (auth()->user() instanceof User ? auth()->user() : null),
            TenantFeatures::forTenant(tenant()),
        );
    }

    /**
     * @return array{
     *     receiving_open: int,
     *     shipping_open: int,
     *     site_id: int|null,
     *     as_of: Carbon
     * }
     */
    public function floorQueue(): array
    {
        $siteId = CurrentSite::id();

        return [
            'receiving_open' => $this->activeSessionCount(ReceivingSession::query(), $siteId),
            'shipping_open' => $this->activeSessionCount(OutboundShippingSession::query(), $siteId),
            'site_id' => $siteId,
            'as_of' => now(),
        ];
    }

    /**
     * @return array{
     *     receives_completed: int,
     *     ships_completed: int,
     *     exceptions_opened: int,
     *     vrs_allowed: int|null,
     *     vrs_blocked: int|null,
     *     since: Carbon,
     *     as_of: Carbon
     * }
     */
    public function todayActivity(): array
    {
        $since = now()->subDay();
        $siteId = CurrentSite::id();

        $receives = ReceivingSession::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since);
        $this->constrainSessionSite($receives, $siteId);

        $ships = OutboundShippingSession::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since);
        $this->constrainSessionSite($ships, $siteId);

        $exceptions = ExceptionCase::query()->where('created_at', '>=', $since);
        SiteAccess::constrainExceptionCases($exceptions, $this->user);

        $vrsAllowed = null;
        $vrsBlocked = null;

        if (
            $this->features->supportsVrs()
            && $this->user instanceof User
            && $this->user->can(Permissions::SitesAccessAll)
        ) {
            $scorecard = app(VerificationScorecardMetrics::class)->handle($since);
            $vrsAllowed = $scorecard['allowed'];
            $vrsBlocked = $scorecard['blocked'];
        }

        return [
            'receives_completed' => (int) $receives->count(),
            'ships_completed' => (int) $ships->count(),
            'exceptions_opened' => (int) $exceptions->count(),
            'vrs_allowed' => $vrsAllowed,
            'vrs_blocked' => $vrsBlocked,
            'since' => $since,
            'as_of' => now(),
        ];
    }

    /**
     * @return array{
     *     open_exceptions: int,
     *     open_quarantine_holds: int,
     *     tracing_at_risk: list<array{id: int, title: string, due_at: Carbon|null, overdue: bool}>,
     *     as_of: Carbon
     * }
     */
    public function compliancePulse(): array
    {
        $exceptions = ExceptionCase::query()->open();
        SiteAccess::constrainExceptionCases($exceptions, $this->user);

        $holds = QuarantineHold::query()->open();
        SiteAccess::constrainExceptionCaseRelation($holds, 'exception', $this->user);

        $tracing = [];

        if ($this->features->supportsTracingRequests()) {
            $query = TracingRequest::query()
                ->whereIn('status', [
                    TracingRequestStatus::Open->value,
                    TracingRequestStatus::InProgress->value,
                ])
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now()->addHours(48))
                ->orderBy('due_at')
                ->limit(3);

            SiteAccess::constrainExceptionCaseRelation($query, 'exceptionCase', $this->user);

            $tracing = $query->get(['id', 'title', 'due_at', 'status', 'responded_at'])
                ->map(fn (TracingRequest $request): array => [
                    'id' => (int) $request->getKey(),
                    'title' => (string) $request->title,
                    'due_at' => $request->due_at,
                    'overdue' => $request->isOverdue(),
                ])
                ->all();
        }

        return [
            'open_exceptions' => $this->features->supportsComplianceCases() ? (int) $exceptions->count() : 0,
            'open_quarantine_holds' => $this->features->supportsComplianceCases() ? (int) $holds->count() : 0,
            'tracing_at_risk' => $tracing,
            'as_of' => now(),
        ];
    }

    /**
     * @return array{inbound_errors: int, outbound_failed: int, as_of: Carbon}
     */
    public function integrationHealth(): array
    {
        $metrics = app(IntegrationHealthMetrics::class);
        $inbound = $metrics->inboundStatusCountsLast24h($this->user);
        $outbound = $metrics->outboundTransmissionCountsLast24h($this->user);

        return [
            'inbound_errors' => (int) ($inbound['error'] ?? 0),
            'outbound_failed' => (int) ($outbound['failed'] ?? 0),
            'as_of' => now(),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function activeSessionCount($query, ?int $siteId): int
    {
        if ($siteId === null) {
            return 0;
        }

        return (int) $query
            ->whereIn('status', ['open', 'in_progress'])
            ->where('site_id', $siteId)
            ->count();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function constrainSessionSite($query, ?int $siteId): void
    {
        if ($siteId !== null) {
            $query->where('site_id', $siteId);

            return;
        }

        if (! $this->user instanceof User) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereIn('site_id', SiteAccess::userSiteIds($this->user));
    }
}
