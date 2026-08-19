<?php

declare(strict_types=1);

namespace App\Services\Labeling;

use App\Models\Epcis\Epc;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Services\Custody\EpcCustodyGate;
use InvalidArgumentException;

/**
 * Custody + quarantine gate for the child EPCs a parent SSCC claims to contain.
 *
 * Attaching a child — whether at generation, from the batch page, or when an EPCIS
 * aggregation is emitted for it later — authors a claim that we hold the unit, so every
 * child must be in tenant custody and free of open holds ({@see EpcCustodyGate}).
 *
 * Unknown URNs fail closed. A serial with no EPC row is one we have never seen on any
 * event: there is no history to substantiate the claim, and accepting it would let an
 * operator seed a hierarchy out of a typed string. Children are received or commissioned
 * first, then packed.
 */
final class SsccChildCustodyGuard
{
    public function __construct(private readonly EpcCustodyGate $custodyGate) {}

    /**
     * @param  array<string, mixed>  $input  GenerateSsccLabelBatch input
     *
     * @throws InvalidArgumentException
     */
    public function assertBatchInputOperable(array $input, string $operation = 'packing'): void
    {
        $urns = [];

        if (! empty($input['child_epcs_per_label']) && is_array($input['child_epcs_per_label'])) {
            foreach ($input['child_epcs_per_label'] as $labelChildren) {
                foreach ((array) $labelChildren as $childEpc) {
                    $urns[] = (string) $childEpc;
                }
            }
        } elseif (! empty($input['child_epcs'])) {
            $urns = $this->splitLines((string) $input['child_epcs']);
        }

        $this->assertUrnsOperable($urns, $operation);
    }

    /**
     * Gate a newline-separated operator submission (batch page children editor).
     *
     * @throws InvalidArgumentException
     */
    public function assertMultilineOperable(string $multilineEpcs, string $operation = 'packing'): void
    {
        $this->assertUrnsOperable($this->splitLines($multilineEpcs), $operation);
    }

    /**
     * Gate the children already attached to a batch, before an EPCIS document asserts them.
     *
     * @throws InvalidArgumentException
     */
    public function assertBatchChildrenOperable(SsccLabelBatch $batch, string $operation = 'packing'): void
    {
        $batch->loadMissing('labels.children');

        $urns = $batch->labels
            ->flatMap(fn (SsccLabel $label) => $label->children)
            ->map(fn ($child): string => (string) $child->child_epc)
            ->all();

        $this->assertUrnsOperable($urns, $operation);
    }

    /**
     * @param  iterable<string>  $urns
     *
     * @throws InvalidArgumentException when a URN is unknown, out of custody, or held
     */
    public function assertUrnsOperable(iterable $urns, string $operation = 'packing'): void
    {
        $urns = $this->normalize($urns);

        if ($urns === []) {
            return;
        }

        $children = Epc::query()->whereIn('epc_uri', $urns)->get();

        $known = $children->map(fn (Epc $epc): string => (string) $epc->epc_uri)->all();
        $unknown = array_values(array_diff($urns, $known));

        if ($unknown !== []) {
            throw new InvalidArgumentException($this->unknownUrnMessage($unknown, $operation));
        }

        $this->custodyGate->assertOperableFor($children->all(), $operation);
    }

    /**
     * @param  list<string>  $unknown
     */
    private function unknownUrnMessage(array $unknown, string $operation): string
    {
        $shown = array_slice($unknown, 0, 5);
        $overflow = count($unknown) - count($shown);
        $single = count($unknown) === 1;

        return 'Unknown child EPC '.($single ? 'URN' : 'URNs').': '.implode(', ', $shown).
            ($overflow > 0 ? ' (+'.$overflow.' more)' : '').
            '. Receive or commission '.($single ? 'it' : 'them').' before '.$operation.
            ' — custody cannot be established for EPCs that are not on record.';
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $multilineEpcs): array
    {
        return $this->normalize(preg_split('/\R/', $multilineEpcs) ?: []);
    }

    /**
     * @param  iterable<string>  $urns
     * @return list<string>
     */
    private function normalize(iterable $urns): array
    {
        $trimmed = [];

        foreach ($urns as $urn) {
            $urn = trim((string) $urn);

            if ($urn !== '') {
                $trimmed[] = $urn;
            }
        }

        return array_values(array_unique($trimmed));
    }
}
