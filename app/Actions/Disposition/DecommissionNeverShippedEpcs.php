<?php

namespace App\Actions\Disposition;

use App\Enums\DecommissionReason;
use App\Enums\ExceptionStatus;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Services\Exceptions\ExceptionService;
use App\Support\Disposition\AssertDecommissionMassApproval;
use App\Support\Disposition\FindNeverShippedCommissionedEpcs;
use App\Support\Receiving\EligibleReceiveSites;
use Database\Seeders\ExceptionTypeSeeder;
use Throwable;

/**
 * Printed-never-shipped auto-decommission.
 *
 * System actor: scheduled command `disposition:decommission-never-shipped`
 * (no tenant user). Does not pass approver_user_id. TP-406 mass SoD is
 * honored by emitting at most `threshold - recentDecommissionedEpcCount(site)`
 * EPCs per site per run, one EPC per EmitDecommissioningEpcis call.
 */
final class DecommissionNeverShippedEpcs
{
    public const EXCEPTION_CODE = 'AUTO_DECOMMISSION_FAILED';

    public function __construct(
        private readonly FindNeverShippedCommissionedEpcs $finder,
        private readonly EmitDecommissioningEpcis $emitDecommissioningEpcis,
        private readonly AssertDecommissionMassApproval $assertMassApproval,
        private readonly ExceptionService $exceptionService,
    ) {}

    /**
     * @return array{decommissioned: int, skipped: int, failed: int}
     */
    public function handle(?int $onlySiteId = null): array
    {
        $holdDays = max(1, (int) config('tracepharma.decommission.unshipped_hold_days', 30));
        $cutoff = now()->subDays($holdDays);
        $decommissioned = 0;
        $skipped = 0;
        $failed = 0;

        $sites = EligibleReceiveSites::forOrganization();
        if ($onlySiteId !== null && $onlySiteId > 0) {
            $sites->whereKey($onlySiteId);
        }
        $sites = $sites->get();

        foreach ($sites as $site) {
            $siteId = (int) $site->getKey();
            $remaining = max(
                0,
                $this->assertMassApproval->threshold() - $this->assertMassApproval->recentDecommissionedEpcCount($siteId),
            );
            $candidates = $this->finder->atSite($siteId, $cutoff);

            if ($remaining === 0) {
                $skipped += count($candidates);

                continue;
            }

            $batch = array_slice($candidates, 0, $remaining);
            $skipped += count($candidates) - count($batch);

            foreach ($batch as $epcId) {
                try {
                    $this->emitDecommissioningEpcis->handle(
                        [$epcId],
                        $siteId,
                        [
                            'sync' => true,
                            'dispatch' => true,
                            'reason' => DecommissionReason::QaRejectNeverShipped,
                        ],
                    );
                    $decommissioned++;
                } catch (Throwable $exception) {
                    $failed++;
                    $this->openFailureCase($epcId, $siteId, $exception->getMessage());
                }
            }
        }

        return [
            'decommissioned' => $decommissioned,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    private function openFailureCase(int $epcId, int $siteId, string $detail): void
    {
        $type = $this->exceptionType();
        if ($type === null) {
            return;
        }

        $alreadyOpen = ExceptionCase::query()
            ->where('exception_type_id', $type->getKey())
            ->whereNotIn('status', [
                ExceptionStatus::Resolved->value,
                ExceptionStatus::Closed->value,
                ExceptionStatus::Cancelled->value,
            ])
            ->whereHas('epcs', fn ($query) => $query->where('epcs.id', $epcId))
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        $this->exceptionService->create([
            'exception_type_id' => $type->getKey(),
            'site_id' => $siteId,
            'title' => $type->name,
            'description' => 'Printed-never-shipped auto-decommission failed: '.$detail,
            'status' => ExceptionStatus::New->value,
        ], [$epcId]);
    }

    private function exceptionType(): ?ExceptionType
    {
        $type = ExceptionType::query()->where('code', self::EXCEPTION_CODE)->first();
        if ($type instanceof ExceptionType) {
            return $type;
        }

        (new ExceptionTypeSeeder)->run();

        return ExceptionType::query()->where('code', self::EXCEPTION_CODE)->first();
    }
}
