<?php

namespace App\Actions\Fda;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\OrganizationMatch;
use App\Support\Fda\OrganizationMatcher;
use Illuminate\Database\QueryException;

/**
 * Apply the four-band FDA organization policy and keep an in-memory index in sync.
 *
 * @phpstan-type OrgIndexRow array{id: int, canonical_name: string, duns_number: ?string}
 */
final class ResolveFdaOrganization
{
    public function __construct(
        private readonly OrganizationMatcher $matcher,
    ) {}

    /**
     * @param  list<OrgIndexRow>  $index
     * @param  array<string, mixed>  $payload
     * @return array{organization: ?FdaOrganization, created: bool, reviewed: bool}
     */
    public function handle(
        string $source,
        string $originalName,
        ?string $duns,
        array &$index,
        array $payload = [],
    ): array {
        $canonical = CompanyNameNormalizer::canonical($originalName);
        $duns = OrganizationMatcher::normalizeDuns($duns);

        if ($canonical === '') {
            $this->review($source, $originalName, $canonical, $duns, null, null, $payload);

            return ['organization' => null, 'created' => false, 'reviewed' => true];
        }

        $match = $this->matcher->match(
            $originalName,
            $duns,
            $index,
            strictIdentity: $source === 'decrs',
        );

        if ($match->action === OrganizationMatch::ACTION_LINK && $match->fdaOrganizationId !== null) {
            $org = FdaOrganization::query()->find($match->fdaOrganizationId);

            if ($org instanceof FdaOrganization) {
                if ($duns !== null && $org->duns_number === null) {
                    $org->fillFromFda(['duns_number' => $duns]);
                    $this->syncIndexDuns($index, (int) $org->id, $duns);
                }

                return ['organization' => $org, 'created' => false, 'reviewed' => false];
            }
        }

        if ($match->action === OrganizationMatch::ACTION_REVIEW) {
            $this->review(
                $source,
                $originalName,
                $canonical,
                $duns,
                $match->fdaOrganizationId,
                $match->confidence,
                $payload
            );

            return ['organization' => null, 'created' => false, 'reviewed' => true];
        }

        try {
            $org = FdaOrganization::query()->create([
                'original_name' => $originalName,
                'canonical_name' => $canonical,
                'name' => $originalName,
                'duns_number' => $duns,
                'partner_type' => match ($source) {
                    'decrs' => PartnerType::Manufacturer,
                    'wdd' => PartnerType::Wholesaler,
                    default => null,
                },
            ]);
        } catch (QueryException $exception) {
            // Prefer DUNS (unique). Canonical is no longer unique across different DUNS.
            $org = ($duns !== null
                    ? FdaOrganization::query()->where('duns_number', $duns)->first()
                    : null)
                ?? FdaOrganization::query()->where('canonical_name', $canonical)->whereNull('duns_number')->first()
                ?? ($duns === null
                    ? FdaOrganization::query()->where('canonical_name', $canonical)->first()
                    : null);

            if (! $org instanceof FdaOrganization) {
                throw $exception;
            }

            return ['organization' => $org, 'created' => false, 'reviewed' => false];
        }

        $index[] = [
            'id' => (int) $org->id,
            'canonical_name' => $canonical,
            'duns_number' => $duns,
        ];

        return ['organization' => $org, 'created' => true, 'reviewed' => false];
    }

    /**
     * @param  list<OrgIndexRow>  $index
     */
    private function syncIndexDuns(array &$index, int $id, string $duns): void
    {
        foreach ($index as $i => $row) {
            if ($row['id'] === $id) {
                $index[$i]['duns_number'] = $duns;

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function review(
        string $source,
        string $originalName,
        string $canonical,
        ?string $duns,
        ?int $proposedId,
        ?float $confidence,
        array $payload,
    ): void {
        $pending = FdaOrganizationMatchReview::query()
            ->pending()
            ->where('source', $source)
            ->where('original_name', $originalName)
            ->when(
                $proposedId === null,
                static fn ($q) => $q->whereNull('proposed_fda_organization_id'),
                static fn ($q) => $q->where('proposed_fda_organization_id', $proposedId),
            )
            ->orderBy('id')
            ->first();

        if ($pending instanceof FdaOrganizationMatchReview) {
            $updates = [
                'canonical_name' => $canonical !== '' ? $canonical : null,
                'payload_json' => $payload,
            ];

            if ($confidence !== null
                && ($pending->confidence === null || $confidence > (float) $pending->confidence)
            ) {
                $updates['confidence'] = $confidence;
            }

            if ($pending->duns_number === null && $duns !== null) {
                $updates['duns_number'] = $duns;
            }

            $pending->forceFill($updates)->save();

            return;
        }

        FdaOrganizationMatchReview::query()->create([
            'source' => $source,
            'original_name' => $originalName,
            'canonical_name' => $canonical !== '' ? $canonical : null,
            'duns_number' => $duns,
            'proposed_fda_organization_id' => $proposedId,
            'confidence' => $confidence,
            'status' => FdaOrganizationMatchReview::STATUS_PENDING,
            'payload_json' => $payload,
        ]);
    }
}
