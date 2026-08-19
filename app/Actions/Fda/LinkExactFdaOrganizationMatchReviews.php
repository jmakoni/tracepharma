<?php

namespace App\Actions\Fda;

use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\OrganizationMatch;
use App\Support\Fda\OrganizationMatcher;
use Throwable;

/**
 * Auto-link pending match reviews when the current matcher LINKs the original
 * name to the proposed organization (e.g. after CoLtd / Factory normalizer fixes).
 */
final class LinkExactFdaOrganizationMatchReviews
{
    public function __construct(
        private readonly OrganizationMatcher $matcher,
        private readonly ResolveFdaOrganizationMatchReview $resolver,
    ) {}

    /**
     * @return array{scanned: int, linked: int, skipped: int, failed: int, samples: list<string>}
     */
    public function handle(?string $source = null, bool $dryRun = false): array
    {
        $query = FdaOrganizationMatchReview::query()
            ->pending()
            ->whereNotNull('proposed_fda_organization_id')
            ->orderBy('id');

        if ($source !== null && $source !== '') {
            $query->where('source', $source);
        }

        $scanned = 0;
        $linked = 0;
        $skipped = 0;
        $failed = 0;
        /** @var list<string> $samples */
        $samples = [];

        foreach ($query->cursor() as $review) {
            $scanned++;
            $proposedId = (int) $review->proposed_fda_organization_id;
            $org = FdaOrganization::query()->find($proposedId);

            if (! $org instanceof FdaOrganization) {
                $skipped++;

                continue;
            }

            $match = $this->matcher->match(
                (string) $review->original_name,
                $review->duns_number,
                [[
                    'id' => (int) $org->id,
                    'canonical_name' => (string) $org->canonical_name,
                    'duns_number' => $org->duns_number,
                ]],
                strictIdentity: $review->source === 'decrs',
            );

            if (
                $match->action !== OrganizationMatch::ACTION_LINK
                || $match->fdaOrganizationId !== $proposedId
            ) {
                $skipped++;

                continue;
            }

            $this->pushSample(
                $samples,
                (string) $review->original_name.' → '.$org->canonical_name.' ('.$match->reason.')',
            );

            if ($dryRun) {
                $linked++;

                continue;
            }

            try {
                $fresh = $review->fresh() ?? $review;
                $this->resolver->link($fresh, $org, null);

                $newCanonical = CompanyNameNormalizer::canonical(
                    (string) ($org->original_name ?: $org->canonical_name)
                );
                if ($newCanonical !== '' && $newCanonical !== $org->canonical_name) {
                    $org->forceFill(['canonical_name' => $newCanonical])->save();
                }

                $linked++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'scanned' => $scanned,
            'linked' => $linked,
            'skipped' => $skipped,
            'failed' => $failed,
            'samples' => $samples,
        ];
    }

    /**
     * @param  list<string>  $samples
     */
    private function pushSample(array &$samples, string $line): void
    {
        if (count($samples) >= 20) {
            return;
        }

        $samples[] = $line;
    }
}
