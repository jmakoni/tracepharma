<?php

namespace Tests\Feature\Fda;

use App\Actions\Fda\ResolveStaleFdaOrganizationMatchReviews;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Support\Fda\CompanyNameNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveStaleFdaOrganizationMatchReviewsTest extends TestCase
{
    private const PREFIX = 'SSOR STALE';

    /** @var list<int> */
    private array $organizationIds = [];

    /** @var list<int> */
    private array $reviewIds = [];

    protected function tearDown(): void
    {
        if ($this->reviewIds !== []) {
            FdaOrganizationMatchReview::query()->whereIn('id', $this->reviewIds)->delete();
        }

        FdaOrganizationMatchReview::query()
            ->where(function ($query): void {
                $query->where('original_name', 'like', self::PREFIX.'%')
                    ->orWhere('original_name', 'like', '%STALEYS%')
                    ->orWhere('original_name', 'like', '%STALEKEEP%')
                    ->orWhere('original_name', 'like', '%STALEYSDRY%')
                    ->orWhere('original_name', 'like', '%STALEDECRS%');
            })
            ->delete();

        if ($this->organizationIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->organizationIds)->delete();
        }

        FdaOrganization::query()
            ->where(function ($query): void {
                $query->where('canonical_name', 'like', self::PREFIX.'%')
                    ->orWhere('canonical_name', 'like', '%STALEASC%')
                    ->orWhere('canonical_name', 'like', '%STALEKEEP%')
                    ->orWhere('canonical_name', 'like', '%STALEDECRS%')
                    ->orWhere('original_name', 'like', self::PREFIX.'%')
                    ->orWhere('name', 'like', self::PREFIX.'%');
            })
            ->delete();

        // Orgs created by the resolver for stale YS rows.
        FdaOrganization::query()
            ->where('canonical_name', 'like', 'YS MARKETING%STALE%')
            ->delete();

        parent::tearDown();
    }

    #[Test]
    public function stale_marketing_only_proposal_creates_separate_organization(): void
    {
        // No shared distinctive tokens after stopwords (MARKETING) and short brands (YS/ASC).
        $proposed = $this->organization('ASC MARKETING STALEASC7', 'ASC Marketing LTD STALEASC7');
        $original = 'YS Marketing Inc STALEYS7';
        $review = $this->pendingReview($original, (int) $proposed->id, 88.0);

        $result = app(ResolveStaleFdaOrganizationMatchReviews::class)->handle(
            source: 'wdd',
            dryRun: false,
        );

        $this->assertGreaterThanOrEqual(1, $result['stale']);
        $this->assertGreaterThanOrEqual(1, $result['resolved']);

        $review->refresh();
        $this->assertSame(FdaOrganizationMatchReview::STATUS_CREATED_NEW, $review->status);
        $this->assertNotNull($review->resolved_fda_organization_id);
        $this->assertNotSame((int) $proposed->id, (int) $review->resolved_fda_organization_id);
        $this->assertNull($review->resolved_by_admin_id);

        $created = FdaOrganization::query()->find($review->resolved_fda_organization_id);
        $this->assertNotNull($created);
        $this->organizationIds[] = (int) $created->id;
        $this->assertSame(CompanyNameNormalizer::canonical($original), $created->canonical_name);

        $proposed->refresh();
        $this->assertSame('ASC MARKETING STALEASC7', $proposed->canonical_name);
    }

    #[Test]
    public function former_mid_band_proposal_is_stale_and_creates_separate_organization(): void
    {
        $proposed = $this->organization('STALEKEEP MERCK SHARP DOHME', 'STALEKEEP Merck Sharp Dohme');
        $original = 'STALEKEEP Merck Sharp';
        $review = $this->pendingReview($original, (int) $proposed->id, 80.0);

        $result = app(ResolveStaleFdaOrganizationMatchReviews::class)->handle(
            source: 'wdd',
            dryRun: false,
        );

        $review->refresh();
        $this->assertSame(FdaOrganizationMatchReview::STATUS_CREATED_NEW, $review->status);
        $this->assertNotNull($review->resolved_fda_organization_id);
        $this->assertNotSame((int) $proposed->id, (int) $review->resolved_fda_organization_id);
        $this->assertGreaterThanOrEqual(1, $result['stale']);
        $this->assertGreaterThanOrEqual(1, $result['resolved']);

        $created = FdaOrganization::query()->find($review->resolved_fda_organization_id);
        $this->assertNotNull($created);
        $this->organizationIds[] = (int) $created->id;
    }

    #[Test]
    public function dry_run_does_not_resolve_stale_reviews(): void
    {
        $proposed = $this->organization('ASC MARKETING STALEASCDRY', 'ASC Marketing Dry LTD');
        $original = 'YS Marketing Inc STALEYSDRY';
        $review = $this->pendingReview($original, (int) $proposed->id, 88.0);

        $result = app(ResolveStaleFdaOrganizationMatchReviews::class)->handle(
            source: 'wdd',
            dryRun: true,
        );

        $this->assertGreaterThanOrEqual(1, $result['stale']);
        $this->assertSame(0, $result['resolved']);

        $review->refresh();
        $this->assertSame(FdaOrganizationMatchReview::STATUS_PENDING, $review->status);
        $this->assertNull($review->resolved_fda_organization_id);
    }

    #[Test]
    public function decrs_prefix_like_proposal_is_stale_under_strict_identity(): void
    {
        $proposed = $this->organization('STALEDECRS FRESENIUS KABI', 'STALEDECRS Fresenius Kabi');
        $original = 'STALEDECRS Fresenius Kabi Austria GmbH';
        $review = FdaOrganizationMatchReview::query()->create([
            'source' => 'decrs',
            'original_name' => $original,
            'canonical_name' => CompanyNameNormalizer::canonical($original),
            'duns_number' => '333444555',
            'proposed_fda_organization_id' => $proposed->id,
            'confidence' => 99.0,
            'status' => FdaOrganizationMatchReview::STATUS_PENDING,
            'payload_json' => ['fei_number' => '0000099999'],
        ]);
        $this->reviewIds[] = (int) $review->id;

        $result = app(ResolveStaleFdaOrganizationMatchReviews::class)->handle(
            source: 'decrs',
            dryRun: false,
        );

        $this->assertGreaterThanOrEqual(1, $result['stale']);
        $this->assertGreaterThanOrEqual(1, $result['resolved']);

        $review->refresh();
        $this->assertSame(FdaOrganizationMatchReview::STATUS_CREATED_NEW, $review->status);
        $this->assertNotSame((int) $proposed->id, (int) $review->resolved_fda_organization_id);

        $created = FdaOrganization::query()->find($review->resolved_fda_organization_id);
        $this->assertNotNull($created);
        $this->organizationIds[] = (int) $created->id;
        $this->assertSame('333444555', $created->duns_number);
    }

    private function organization(string $canonical, string $original): FdaOrganization
    {
        $org = FdaOrganization::query()->create([
            'original_name' => $original,
            'canonical_name' => $canonical,
            'name' => $original,
            'duns_number' => null,
        ]);
        $this->organizationIds[] = (int) $org->id;

        return $org;
    }

    private function pendingReview(string $originalName, int $proposedId, float $confidence): FdaOrganizationMatchReview
    {
        $review = FdaOrganizationMatchReview::query()->create([
            'source' => 'wdd',
            'original_name' => $originalName,
            'canonical_name' => CompanyNameNormalizer::canonical($originalName),
            'duns_number' => null,
            'proposed_fda_organization_id' => $proposedId,
            'confidence' => $confidence,
            'status' => FdaOrganizationMatchReview::STATUS_PENDING,
            'payload_json' => ['facility_name' => $originalName],
        ]);
        $this->reviewIds[] = (int) $review->id;

        return $review;
    }
}
