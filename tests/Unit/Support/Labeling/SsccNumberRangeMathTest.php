<?php

namespace Tests\Unit\Support\Labeling;

use App\Enums\SsccNumberRangeStatus;
use App\Models\SsccNumberRange;
use App\Support\Labeling\SsccNumberRangeValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SsccNumberRangeMathTest extends TestCase
{
    #[Test]
    public function utilized_remaining_and_band_helpers(): void
    {
        $range = new SsccNumberRange([
            'start_number' => 100,
            'current_number' => 110,
            'increment_by' => 2,
            'range_size' => 10,
        ]);

        $this->assertSame(5, $range->utilizedSteps());
        $this->assertSame(5, $range->recomputeRemaining());
        $this->assertSame(5, $range->issuableCount());
        $this->assertSame(50.0, $range->utilizationPercentage());
        $this->assertSame(118, $range->lastIssuableNumber());
        $this->assertSame(120, $range->endNumberExclusive());
    }

    #[Test]
    public function depleted_revives_when_remaining_positive(): void
    {
        $range = new SsccNumberRange([
            'start_number' => 100,
            'current_number' => 120,
            'increment_by' => 2,
            'range_size' => 10,
            'status' => SsccNumberRangeStatus::Depleted,
        ]);
        $range->syncRemainingAndStatus();
        $this->assertSame(0, $range->remaining);
        $this->assertSame(SsccNumberRangeStatus::Depleted, $range->status);

        $range->current_number = 110;
        $range->syncRemainingAndStatus();
        $this->assertSame(5, $range->remaining);
        $this->assertSame(SsccNumberRangeStatus::Active, $range->status);
    }

    #[Test]
    public function inactive_status_is_preserved_by_sync(): void
    {
        $range = new SsccNumberRange([
            'start_number' => 1,
            'current_number' => 1,
            'increment_by' => 1,
            'range_size' => 10,
            'status' => SsccNumberRangeStatus::Inactive,
        ]);
        $range->syncRemainingAndStatus();
        $this->assertSame(SsccNumberRangeStatus::Inactive, $range->status);
        $this->assertSame(10, $range->remaining);
    }

    #[Test]
    public function name_must_be_api_safe(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsccNumberRangeValidator::assertName('Bad Name!');
    }

    #[Test]
    public function band_intersects_detects_overlap(): void
    {
        $this->assertTrue(SsccNumberRange::bandIntersects(1, 10, 10, 20));
        $this->assertFalse(SsccNumberRange::bandIntersects(1, 10, 11, 20));
    }
}
