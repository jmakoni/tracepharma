<?php

namespace Tests\Unit\Support\Exceptions;

use App\Models\Exceptions\ExceptionCase;
use App\Support\Exceptions\InvestigatorSlaClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvestigatorSlaClockTest extends TestCase
{
    #[Test]
    public function uses_created_at_plus_72_hours_when_due_at_is_null(): void
    {
        $created = CarbonImmutable::parse('2026-08-20 12:00:00');
        $case = (new ExceptionCase)->forceFill([
            'created_at' => $created,
            'due_at' => null,
        ]);

        $deadline = (new InvestigatorSlaClock)->deadline($case);

        $this->assertSame(
            $created->addHours(72)->getTimestamp(),
            $deadline->getTimestamp(),
        );
    }

    #[Test]
    public function emailed_due_at_is_the_deadline_even_when_later_than_created_plus_72(): void
    {
        $created = now()->subHours(80);
        $emailed = now()->addHours(72);
        $case = (new ExceptionCase)->forceFill([
            'created_at' => $created,
            'due_at' => $emailed,
        ]);

        $clock = new InvestigatorSlaClock;
        $deadline = $clock->deadline($case);

        $this->assertSame($emailed->getTimestamp(), $deadline->getTimestamp());
        $this->assertFalse($clock->isBreached($case));
    }

    #[Test]
    public function tighter_internal_due_at_wins(): void
    {
        $created = CarbonImmutable::parse('2026-08-20 12:00:00');
        $case = (new ExceptionCase)->forceFill([
            'created_at' => $created,
            'due_at' => $created->addHours(8),
        ]);

        $deadline = (new InvestigatorSlaClock)->deadline($case);

        $this->assertSame(
            $created->addHours(8)->getTimestamp(),
            $deadline->getTimestamp(),
        );
    }
}
