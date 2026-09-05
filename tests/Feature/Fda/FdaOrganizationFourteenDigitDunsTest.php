<?php

namespace Tests\Feature\Fda;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Support\Fda\CompanyNameNormalizer;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FdaOrganizationFourteenDigitDunsTest extends TestCase
{
    /** @var list<int> */
    private array $organizationIds = [];

    /** @var list<int> */
    private array $reviewIds = [];

    protected function tearDown(): void
    {
        if ($this->reviewIds !== []) {
            FdaOrganizationMatchReview::query()->whereIn('id', $this->reviewIds)->delete();
        }

        if ($this->organizationIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->organizationIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function organization_persists_fourteen_digit_duns(): void
    {
        $suffix = Str::lower(Str::random(8));
        $name = 'Fourteen Duns Org '.$suffix;
        $duns = '80373640412345';

        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Wholesaler,
            'duns_number' => $duns,
            'is_active' => true,
        ]);
        $this->organizationIds[] = (int) $org->id;

        $this->assertSame($duns, $org->fresh()?->duns_number);
    }

    #[Test]
    public function match_review_persists_fourteen_digit_duns(): void
    {
        $suffix = Str::lower(Str::random(8));
        $duns = '01243088098765';

        $review = FdaOrganizationMatchReview::query()->create([
            'source' => 'wdd',
            'original_name' => 'Fourteen Duns Review '.$suffix,
            'canonical_name' => 'FOURTEEN DUNS REVIEW '.$suffix,
            'duns_number' => $duns,
            'confidence' => 0.5,
            'status' => FdaOrganizationMatchReview::STATUS_PENDING,
            'payload_json' => ['test' => 'fourteen-duns'],
        ]);
        $this->reviewIds[] = (int) $review->id;

        $this->assertSame($duns, $review->fresh()?->duns_number);
    }
}
