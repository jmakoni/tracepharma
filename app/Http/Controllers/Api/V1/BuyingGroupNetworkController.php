<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\BuyingGroupMemberStatus;
use App\Http\Controllers\Controller;
use App\Models\BuyingGroupMember;
use App\Support\BuyingGroup\BuyingGroupEnrollmentAnalytics;
use App\Support\BuyingGroup\BuyingGroupNetworkSnapshots;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buying-group network control-plane APIs (Wave F4).
 * Snapshot/roster payloads only — no EPC / EPCIS document bodies.
 */
final class BuyingGroupNetworkController extends Controller
{
    public function __construct(
        private readonly BuyingGroupNetworkSnapshots $snapshots,
        private readonly BuyingGroupEnrollmentAnalytics $enrollment,
    ) {}

    public function members(Request $request): JsonResponse
    {
        $this->assertBuyingGroupNetwork();

        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));

        $paginator = BuyingGroupMember::query()
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (BuyingGroupMember $member): array => $this->memberPayload($member))
                ->values(),
            'meta' => $this->paginationMeta($paginator->currentPage(), $paginator->lastPage(), $paginator->perPage(), $paginator->total()),
        ]);
    }

    public function readiness(int $id): JsonResponse
    {
        $this->assertBuyingGroupNetwork();

        $member = BuyingGroupMember::query()->findOrFail($id);

        if (! filled($member->member_tenant_id)) {
            return response()->json([
                'data' => [
                    'member' => $this->memberPayload($member),
                    'readiness' => [
                        'as_of' => null,
                        'soft_only' => true,
                        'live_metrics' => false,
                        'atp_gap_count' => 'N/A',
                        'exceptions_open' => 'N/A',
                        'exceptions_aging_7d' => 'N/A',
                        'connection_unhealthy' => 'N/A',
                        'last_epcis_success_at' => null,
                        'health_score' => 'N/A',
                        'risk_score' => 'N/A',
                        'empty_hint' => 'Link tenant for live metrics',
                    ],
                ],
            ]);
        }

        $tenantId = (string) $member->member_tenant_id;
        $row = $this->snapshots->memberHealthRows()
            ->first(fn (array $candidate): bool => ($candidate['member_tenant_id'] ?? null) === $tenantId);

        if ($row === null) {
            $row = [
                'as_of' => null,
                'soft_only' => false,
                'live_metrics' => false,
                'atp_gap_count' => 'N/A',
                'exceptions_open' => 'N/A',
                'exceptions_aging_7d' => 'N/A',
                'connection_unhealthy' => 'N/A',
                'last_epcis_success_at' => null,
                'health_score' => 'N/A',
                'risk_score' => 'N/A',
                'empty_hint' => 'Awaiting first rollup',
            ];
        }

        return response()->json([
            'data' => [
                'member' => $this->memberPayload($member),
                'readiness' => [
                    'as_of' => $row['as_of'] ?? null,
                    'soft_only' => (bool) ($row['soft_only'] ?? false),
                    'live_metrics' => (bool) ($row['live_metrics'] ?? false),
                    'atp_gap_count' => $row['atp_gap_count'] ?? 'N/A',
                    'exceptions_open' => $row['exceptions_open'] ?? 'N/A',
                    'exceptions_aging_7d' => $row['exceptions_aging_7d'] ?? 'N/A',
                    'connection_unhealthy' => $row['connection_unhealthy'] ?? 'N/A',
                    'last_epcis_success_at' => $row['last_epcis_success_at'] ?? null,
                    'health_score' => $row['health_score'] ?? 'N/A',
                    'risk_score' => $row['risk_score'] ?? 'N/A',
                    'empty_hint' => $row['empty_hint'] ?? null,
                ],
            ],
        ]);
    }

    public function networkSummary(): JsonResponse
    {
        $this->assertBuyingGroupNetwork();

        $enrollment = $this->enrollment->summarize();
        $healthRows = $this->snapshots->memberHealthRows();
        $live = $healthRows->filter(fn (array $row): bool => (bool) ($row['live_metrics'] ?? false));

        return response()->json([
            'data' => [
                'affiliation_code' => TenantSettings::forTenant(tenant())->affiliationCode(),
                'enrollment' => $enrollment,
                'health' => [
                    'as_of' => $this->snapshots->latestMetricsAsOf()?->toDateString(),
                    'members_with_live_metrics' => $live->count(),
                    'atp_gap_total' => $live->sum(fn (array $row): int => (int) ($row['atp_gap_count'] ?? 0)),
                    'exceptions_open_total' => $live->sum(fn (array $row): int => (int) ($row['exceptions_open'] ?? 0)),
                    'exceptions_aging_7d_total' => $live->sum(fn (array $row): int => (int) ($row['exceptions_aging_7d'] ?? 0)),
                    'connection_unhealthy_count' => $live->filter(
                        fn (array $row): bool => (bool) ($row['connection_unhealthy'] ?? false),
                    )->count(),
                ],
            ],
        ]);
    }

    public function partnerMatrix(Request $request): JsonResponse
    {
        $this->assertBuyingGroupNetwork();

        $statusFilter = filled($request->input('license_status'))
            ? (string) $request->input('license_status')
            : null;

        $rows = $this->snapshots->partnerMatrixRows(statusFilter: $statusFilter);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $slice = $rows->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $slice,
            'meta' => $this->paginationMeta($page, $lastPage, $perPage, $total),
        ]);
    }

    private function assertBuyingGroupNetwork(): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsBuyingGroupNetwork()) {
            abort(403, 'Buying group network APIs require a BuyingGroup tenant profile.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function memberPayload(BuyingGroupMember $member): array
    {
        return [
            'id' => (int) $member->getKey(),
            'name' => (string) $member->name,
            'external_ref' => $member->external_ref,
            'member_tenant_id' => $member->member_tenant_id,
            'hard_linked' => filled($member->member_tenant_id),
            'status' => $member->status instanceof BuyingGroupMemberStatus
                ? $member->status->value
                : (string) $member->status,
            'contact_email' => $member->contact_email,
            'affiliation_code' => $member->affiliation_code,
            'program_sku' => $member->program_sku,
            'primary_gln' => $member->primary_gln,
            'sites_count' => $member->sites_count,
        ];
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function paginationMeta(int $currentPage, int $lastPage, int $perPage, int $total): array
    {
        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }
}
