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
    public function uses_created_at_plus_72_hours_when_due_at_is_later(): void
    {
        $created = CarbonImmutable::parse('2026-08-20 12:00:00');
        $case = (new ExceptionCase)->forceFill([
            'created_at' => $created,
            'due_at' => $created->addHours(120),
        ]);

        $deadline = (new InvestigatorSlaClock)->deadline($case);

        $this->assertSame(
            $created->addHours(72)->getTimestamp(),
            $deadline->getTimestamp(),
        );
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
