<?php

namespace Tests\Feature\Fda;

use App\Actions\Fda\DedupeFdaOrganizationMatchReviews;
use App\Actions\Fda\ResolveFdaOrganization;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DedupeFdaOrganizationMatchReviewsTest extends TestCase
{
    private const PREFIX = 'SSOR DEDUPE';

    private const DEDUPE_INDEX = 'fda_org_match_reviews_dedupe_uq';

    /** @var list<int> */
    private array $organizationIds = [];

    /** @var list<int> */
    private array $reviewIds = [];

    private bool $restoreDedupeIndex = false;

    protected function tearDown(): void
    {
        if ($this->reviewIds !== []) {
            FdaOrganizationMatchReview::query()->whereIn('id', $this->reviewIds)->delete();
        }

        FdaOrganizationMatchReview::query()
            ->where('original_name', 'like', self::PREFIX.'%')
            ->delete();

        if ($this->organizationIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->organizationIds)->delete();
        }

        FdaOrganization::query()
            ->where(function ($query): void {
                $query->where('canonical_name', 'like', self::PREFIX.'%')
                    ->orWhere('original_name', 'like', self::PREFIX.'%')
                    ->orWhere('name', 'like', self::PREFIX.'%');
            })
            ->delete();

        $this->restoreDedupeUniqueIfNeeded();

        parent::tearDown();
    }

    #[Test]
    public function resolve_dedupes_pending_reviews_for_the_same_name_and_proposed(): void
    {
        $orgA = $this->organization(self::PREFIX.' NOVO NORDISK PHARMA', self::PREFIX.' Novo Nordisk Pharma');
        $orgB = $this->organization(self::PREFIX.' NOVO NORDISK PHARM', self::PREFIX.' Novo Nordisk Pharm');
        $index = [
            [
                'id' => (int) $orgA->id,
                'canonical_name' => self::PREFIX.' NOVO NORDISK PHARMA',
                'duns_number' => null,
            ],
            [
                'id' => (int) $orgB->id,
                'canonical_name' => self::PREFIX.' NOVO NORDISK PHARM',
                'duns_number' => null,
            ],
        ];

        $name = self::PREFIX.' Novo Nordisk Phar';
        $resolver = app(ResolveFdaOrganization::class);

        $first = $resolver->handle('wdd', $name, null, $index);
        $second = $resolver->handle('wdd', $name, null, $index);

        $this->assertTrue($first['reviewed']);
        $this->assertTrue($second['reviewed']);

        $pending = FdaOrganizationMatchReview::query()
            ->pending()
            ->where('source', 'wdd')
            ->where('original_name', $name)
            ->get();

        $this->reviewIds = array_merge($this->reviewIds, $pending->pluck('id')->all());

        $this->assertCount(1, $pending);
        $this->assertContains(
            (int) $pending->first()->proposed_fda_organization_id,
            [(int) $orgA->id, (int) $orgB->id],
        );
    }

    #[Test]
    public function dedupe_action_keeps_min_id_and_deletes_surplus(): void
    {
        $name = self::PREFIX.' Identical Pending';
        $proposed = $this->organization(self::PREFIX.' PROPOSED ORG', self::PREFIX.' Proposed Org');

        $this->dropDedupeUniqueForSeeding();

        $a = $this->pendingReview($name, (int) $proposed->id, 80.0);
        $b = $this->pendingReview($name, (int) $proposed->id, 81.0);
        $c = $this->pendingReview($name, (int) $proposed->id, 82.0);

        $before = FdaOrganizationMatchReview::query()
            ->pending()
            ->where('source', 'wdd')
            ->where('original_name', $name)
            ->count();
        $this->assertSame(3, $before);

        $result = app(DedupeFdaOrganizationMatchReviews::class)->handle('wdd', false);

        $this->assertGreaterThanOrEqual(1, $result['groups']);
        $this->assertGreaterThanOrEqual(2, $result['deleted']);
        $this->assertGreaterThanOrEqual(1, $result['kept']);

        $remaining = FdaOrganizationMatchReview::query()
            ->pending()
            ->where('source', 'wdd')
            ->where('original_name', $name)
            ->get();

        $this->assertCount(1, $remaining);
        $this->assertSame((int) $a->id, (int) $remaining->first()->id);
        $this->assertNull(FdaOrganizationMatchReview::query()->find($b->id));
        $this->assertNull(FdaOrganizationMatchReview::query()->find($c->id));
    }

    #[Test]
    public function command_dry_run_does_not_delete(): void
    {
        $name = self::PREFIX.' Dry Run Pending';
        $proposed = $this->organization(self::PREFIX.' DRY ORG', self::PREFIX.' Dry Org');

        $this->dropDedupeUniqueForSeeding();

        $a = $this->pendingReview($name, (int) $proposed->id, 80.0);
        $b = $this->pendingReview($name, (int) $proposed->id, 81.0);

        $this->artisan('fda:dedupe-match-reviews', [
            '--dry-run' => true,
            '--source' => 'wdd',
        ])
            ->expectsOutputToContain('[dry run]')
            ->assertSuccessful();

        $this->assertNotNull(FdaOrganizationMatchReview::query()->find($a->id));
        $this->assertNotNull(FdaOrganizationMatchReview::query()->find($b->id));
        $this->assertSame(
            2,
            FdaOrganizationMatchReview::query()
                ->pending()
                ->where('source', 'wdd')
                ->where('original_name', $name)
                ->count()
        );
    }

    private function organization(string $canonical, string $name): FdaOrganization
    {
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => $canonical,
            'name' => $name,
            'is_active' => true,
        ]);
        $this->organizationIds[] = (int) $org->id;

        return $org;
    }

    private function pendingReview(string $originalName, ?int $proposedId, float $confidence): FdaOrganizationMatchReview
    {
        $review = FdaOrganizationMatchReview::query()->create([
            'source' => 'wdd',
            'original_name' => $originalName,
            'canonical_name' => strtoupper($originalName),
            'proposed_fda_organization_id' => $proposedId,
            'confidence' => $confidence,
            'status' => FdaOrganizationMatchReview::STATUS_PENDING,
            'payload_json' => ['test' => self::PREFIX],
        ]);
        $this->reviewIds[] = (int) $review->id;

        return $review;
    }

    private function dropDedupeUniqueForSeeding(): void
    {
        $table = (new FdaOrganizationMatchReview)->getTable();

        if (! Schema::hasIndex($table, self::DEDUPE_INDEX)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropUnique(self::DEDUPE_INDEX);
        });
        $this->restoreDedupeIndex = true;
    }

    private function restoreDedupeUniqueIfNeeded(): void
    {
        if (! $this->restoreDedupeIndex) {
            return;
        }

        $table = (new FdaOrganizationMatchReview)->getTable();

        if (! Schema::hasIndex($table, self::DEDUPE_INDEX)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unique(
                    ['source', 'original_name', 'proposed_org_key', 'status'],
                    self::DEDUPE_INDEX
                );
            });
        }

        $this->restoreDedupeIndex = false;
    }
}
