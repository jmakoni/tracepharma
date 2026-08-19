<?php

namespace Tests\Feature\Fda;

use App\Actions\Fda\LinkExactFdaOrganizationMatchReviews;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Support\Fda\CompanyNameNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkExactFdaOrganizationMatchReviewsTest extends TestCase
{
    private const PREFIX = 'LINKEXACT';

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $reviewIds = [];

    protected function tearDown(): void
    {
        if ($this->reviewIds !== []) {
            FdaOrganizationMatchReview::query()->whereIn('id', $this->reviewIds)->delete();
        }

        FdaOrganizationMatchReview::query()
            ->where('original_name', 'like', self::PREFIX.'%')
            ->delete();

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        FdaOrganization::query()
            ->where('canonical_name', 'like', self::PREFIX.'%')
            ->delete();

        parent::tearDown();
    }

    #[Test]
    public function links_pending_review_when_coltd_normalizes_to_same_canonical(): void
    {
        $org = FdaOrganization::query()->create([
            'original_name' => self::PREFIX.' Gaorong Cosmetic CoLtd',
            'canonical_name' => self::PREFIX.' GAORONG COSMETIC COLTD',
            'name' => self::PREFIX.' Gaorong Cosmetic CoLtd',
        ]);
        $this->orgIds[] = (int) $org->id;

        $review = FdaOrganizationMatchReview::query()->create([
            'source' => 'openfda_ndc',
            'original_name' => self::PREFIX.' Gaorong Cosmetic Co., Ltd.',
            'canonical_name' => CompanyNameNormalizer::canonical(self::PREFIX.' Gaorong Cosmetic Co., Ltd.'),
            'proposed_fda_organization_id' => $org->id,
            'confidence' => 89.0,
            'status' => FdaOrganizationMatchReview::STATUS_PENDING,
            'payload_json' => [],
        ]);
        $this->reviewIds[] = (int) $review->id;

        $result = app(LinkExactFdaOrganizationMatchReviews::class)->handle('openfda_ndc', false);

        $this->assertGreaterThanOrEqual(1, $result['linked']);

        $review->refresh();
        $this->assertSame(FdaOrganizationMatchReview::STATUS_LINKED, $review->status);
        $this->assertSame($org->id, $review->resolved_fda_organization_id);

        $org->refresh();
        $this->assertSame(
            CompanyNameNormalizer::canonical((string) $org->original_name),
            $org->canonical_name,
        );
    }
}
