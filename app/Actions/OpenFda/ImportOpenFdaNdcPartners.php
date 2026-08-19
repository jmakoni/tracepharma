<?php

namespace App\Actions\OpenFda;

use App\Actions\Fda\ResolveFdaOrganization;
use App\Models\Fda\FdaOrganization;
use App\Support\Catalog\DisplayName;
use App\Support\PartnerSlug;

/**
 * Resolve fda_organizations for every distinct labeler in an openFDA NDC payload.
 */
final class ImportOpenFdaNdcPartners
{
    public function __construct(
        private readonly ResolveFdaOrganization $orgResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array{skipped_empty: int, orgs_created: int, orgs_reviewed: int, orgs_linked: int}
     */
    public function handle(array $results): array
    {
        $counts = [
            'skipped_empty' => 0,
            'orgs_created' => 0,
            'orgs_reviewed' => 0,
            'orgs_linked' => 0,
        ];

        /** @var array<string, string> $canonicalBySlug slug => first-seen display name */
        $canonicalBySlug = [];

        foreach ($results as $result) {
            $name = DisplayName::clean(trim((string) ($result['labeler_name'] ?? ''))) ?? '';

            if ($name === '') {
                $counts['skipped_empty']++;

                continue;
            }

            $slug = PartnerSlug::from($name);

            if (! isset($canonicalBySlug[$slug])) {
                $canonicalBySlug[$slug] = $name;
            }
        }

        if ($canonicalBySlug === []) {
            return $counts;
        }

        $index = FdaOrganization::query()
            ->get(['id', 'canonical_name', 'duns_number'])
            ->map(static fn (FdaOrganization $org): array => [
                'id' => (int) $org->id,
                'canonical_name' => (string) $org->canonical_name,
                'duns_number' => $org->duns_number,
            ])
            ->values()
            ->all();

        foreach ($canonicalBySlug as $slug => $displayName) {
            $resolved = $this->orgResolver->handle(
                'openfda_ndc',
                $displayName,
                null,
                $index,
                ['slug' => $slug]
            );

            if ($resolved['created']) {
                $counts['orgs_created']++;
            }
            if ($resolved['reviewed']) {
                $counts['orgs_reviewed']++;
            }
            if ($resolved['organization'] instanceof FdaOrganization) {
                $counts['orgs_linked']++;
            }
        }

        return $counts;
    }
}
