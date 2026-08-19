<?php

declare(strict_types=1);

namespace App\Actions\Labeling;

use App\Enums\SsccLabelBatchStatus;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Services\Labeling\SsccChildCustodyGuard;
use App\Services\Labeling\SsccLabelChildAttacher;
use App\Support\Gs1\AssertOrganizationSsccIdentity;
use App\Support\TenantSettings;
use App\Support\TenantSsccSettings;
use InvalidArgumentException;
use Throwable;

/**
 * Continue packing onto an already generated parent SSCC (empty or in-progress).
 *
 * Attaches new children, commissions the parent if needed, then authors a packing
 * AggregationEvent ADD for those new children only.
 */
final class AttachChildrenToExistingSscc
{
    public function __construct(
        private readonly SsccLabelChildAttacher $childAttacher,
        private readonly SsccChildCustodyGuard $childCustodyGuard,
        private readonly EmitSsccBatchCommissioningEpcis $commissioningEmitter,
        private readonly EmitSsccBatchEpcis $epcisEmitter,
    ) {}

    /**
     * @param  list<string>  $childUrns
     * @param  array{site_id?: int|null, epcis_sync?: bool}  $options
     */
    public function execute(SsccLabel $label, array $childUrns, array $options = []): SsccLabelBatch
    {
        $label->loadMissing(['batch', 'children']);
        $batch = $label->batch;

        if ($batch === null) {
            throw new InvalidArgumentException('SSCC label is missing its generation batch.');
        }

        if ($batch->status === SsccLabelBatchStatus::Failed) {
            throw new InvalidArgumentException('Cannot pack onto a failed SSCC batch.');
        }

        $this->assertIssuedByTenant($label);

        $requested = $this->normalizeUrns($childUrns);
        if ($requested === []) {
            throw new InvalidArgumentException('Scan at least one child EPC before packing.');
        }

        $parentEpc = $this->parentEpc($label);
        $alreadyLinked = $this->alreadyLinkedChildUrns($parentEpc, $requested);
        $onLabel = $label->children->pluck('child_epc')->all();

        $toAttach = array_values(array_diff($requested, $onLabel));
        $toEmit = array_values(array_diff($requested, $alreadyLinked));

        if ($toAttach === [] && $toEmit === []) {
            return $batch->fresh(['labels.children']) ?? $batch;
        }

        $this->childCustodyGuard->assertUrnsOperable($toEmit !== [] ? $toEmit : $toAttach);

        if ($toAttach !== []) {
            $this->childAttacher->attachToLabel($label, implode("\n", $toAttach));
        }

        $siteId = isset($options['site_id']) && $options['site_id'] !== null && $options['site_id'] !== ''
            ? (int) $options['site_id']
            : ($batch->commission_site_id !== null ? (int) $batch->commission_site_id : null);
        $sync = (bool) ($options['epcis_sync'] ?? true);

        $label->refresh();
        $batch->refresh();

        if ($label->commissioned_at === null || $batch->commissioned_at === null) {
            try {
                $this->commissioningEmitter->execute($batch, [
                    'site_id' => $siteId,
                    'sync' => true,
                ]);
            } catch (Throwable $exception) {
                $this->appendBatchError($batch, 'Commissioning: '.$exception->getMessage());

                throw $exception;
            }

            $label->refresh();
            $batch->refresh();
        }

        if ($toEmit !== []) {
            try {
                $this->epcisEmitter->forNewChildren($label, $toEmit, [
                    'site_id' => $siteId,
                    'sync' => $sync,
                ]);
            } catch (Throwable $exception) {
                $this->appendBatchError($batch, 'EPCIS aggregation: '.$exception->getMessage());

                throw $exception;
            }
        }

        return $batch->fresh(['labels.children']) ?? $batch;
    }

    private function assertIssuedByTenant(SsccLabel $label): void
    {
        $tenantPrefix = (string) (TenantSsccSettings::resolve()['company_prefix'] ?? '');
        $labelPrefix = (string) $label->company_prefix;

        if ($tenantPrefix === '' || $labelPrefix !== $tenantPrefix) {
            throw new InvalidArgumentException(
                'This SSCC was not issued under this organization company prefix.',
            );
        }

        app(AssertOrganizationSsccIdentity::class)->handle(
            TenantSettings::forTenant(tenant())->gln(),
            $labelPrefix,
        );
    }

    /**
     * @param  list<string>  $childUrns
     * @return list<string>
     */
    private function normalizeUrns(array $childUrns): array
    {
        $trimmed = [];

        foreach ($childUrns as $urn) {
            $urn = trim((string) $urn);
            if ($urn !== '') {
                $trimmed[] = $urn;
            }
        }

        return array_values(array_unique($trimmed));
    }

    private function parentEpc(SsccLabel $label): ?Epc
    {
        $urn = trim((string) $label->sscc_urn);
        if ($urn !== '') {
            $byUrn = Epc::query()->where('epc_uri', $urn)->first();
            if ($byUrn instanceof Epc) {
                return $byUrn;
            }
        }

        $sscc18 = trim((string) $label->sscc_18);
        if ($sscc18 !== '') {
            $bySscc = Epc::query()->where('sscc18', $sscc18)->first();
            if ($bySscc instanceof Epc) {
                return $bySscc;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function alreadyLinkedChildUrns(?Epc $parentEpc, array $requested): array
    {
        if ($parentEpc === null || $requested === []) {
            return [];
        }

        $childIds = Epc::query()
            ->whereIn('epc_uri', $requested)
            ->pluck('id', 'epc_uri');

        if ($childIds->isEmpty()) {
            return [];
        }

        $linkedChildIds = AggregationLink::query()
            ->open()
            ->where('parent_epc_id', $parentEpc->getKey())
            ->whereIn('child_epc_id', $childIds->values()->all())
            ->pluck('child_epc_id')
            ->all();

        $linked = [];
        foreach ($childIds as $urn => $id) {
            if (in_array((int) $id, array_map('intval', $linkedChildIds), true)) {
                $linked[] = (string) $urn;
            }
        }

        return $linked;
    }

    private function appendBatchError(SsccLabelBatch $batch, string $line): void
    {
        $existing = trim((string) $batch->error_message);
        $batch->update([
            'error_message' => $existing === '' ? $line : $existing."\n".$line,
        ]);
    }
}
