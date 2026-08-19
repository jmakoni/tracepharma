<?php

namespace App\Actions\Fda;

use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Support\Fda\OrganizationMatch;
use App\Support\Fda\OrganizationMatcher;
use Throwable;

/**
 * Re-score pending match reviews against the current matcher and create separate
 * organizations when the proposed org is no longer a valid fuzzy hit.
 */
final class ResolveStaleFdaOrganizationMatchReviews
{
    public function __construct(
        private readonly OrganizationMatcher $matcher,
        private readonly ResolveFdaOrganizationMatchReview $resolver,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     stale: int,
     *     resolved: int,
     *     kept: int,
     *     failed: int,
     *     samples: list<string>
     * }
     */
    public function handle(?string $source = null, bool $dryRun = false, ?int $limit = null): array
    {
        $sourceFilter = ($source !== null && $source !== '') ? $source : null;
        $limit = ($limit !== null && $limit > 0) ? $limit : null;

        $query = FdaOrganizationMatchReview::query()
            ->pending()
            ->whereNotNull('proposed_fda_organization_id')
            ->orderBy('id');

        if ($sourceFilter !== null) {
            $query->where('source', $sourceFilter);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var list<FdaOrganizationMatchReview> $reviews */
        $reviews = $query->get()->all();

        $orgIds = [];
        foreach ($reviews as $review) {
            $orgIds[(int) $review->proposed_fda_organization_id] = true;
        }

        $orgs = FdaOrganization::query()
            ->whereIn('id', array_keys($orgIds))
            ->get(['id', 'canonical_name', 'duns_number'])
            ->keyBy('id');

        $scanned = 0;
        $stale = 0;
        $resolved = 0;
        $kept = 0;
        $failed = 0;
        /** @var list<string> $samples */
        $samples = [];

        foreach ($reviews as $review) {
            $scanned++;
            $proposedId = (int) $review->proposed_fda_organization_id;
            $org = $orgs->get($proposedId);

            if (! $org instanceof FdaOrganization) {
                $stale++;
                $this->pushSample($samples, (string) $review->original_name.' -> (missing proposed #'.$proposedId.')');

                if (! $dryRun) {
                    try {
                        $this->resolver->createOrganization($review, null);
                        $resolved++;
                    } catch (Throwable) {
                        $failed++;
                    }
                }

                continue;
            }

            $index = [[
                'id' => (int) $org->id,
                'canonical_name' => (string) $org->canonical_name,
                'duns_number' => $org->duns_number,
            ]];

            $match = $this->matcher->match(
                (string) $review->original_name,
                $review->duns_number,
                $index,
                strictIdentity: $review->source === 'decrs',
            );

            $stillPointsAtProposed = in_array($match->action, [
                OrganizationMatch::ACTION_LINK,
                OrganizationMatch::ACTION_REVIEW,
            ], true) && $match->fdaOrganizationId === $proposedId;

            if ($stillPointsAtProposed) {
                $kept++;

                continue;
            }

            $stale++;
            $this->pushSample(
                $samples,
                (string) $review->original_name.' -> '.$org->canonical_name.' now='.$match->action,
            );

            if ($dryRun) {
                continue;
            }

            try {
                $this->resolver->createOrganization($review->fresh() ?? $review, null);
                $resolved++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'scanned' => $scanned,
            'stale' => $stale,
            'resolved' => $dryRun ? 0 : $resolved,
            'kept' => $kept,
            'failed' => $failed,
            'samples' => $samples,
        ];
    }

    /**
     * @param  list<string>  $samples
     */
    private function pushSample(array &$samples, string $line): void
    {
        if (count($samples) >= 15) {
            return;
        }

        $samples[] = $line;
    }
}
