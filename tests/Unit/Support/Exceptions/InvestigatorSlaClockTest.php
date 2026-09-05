<?php

namespace Tests\Unit\Support\Exceptions;

use App\Enums\ExceptionActivityKind;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Support\Exceptions\InvestigatorSlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
    public function uses_created_at_plus_72_hours_when_due_at_is_later_and_not_emailed(): void
    {
        $created = CarbonImmutable::parse('2026-08-20 12:00:00');
        $case = (new ExceptionCase)->forceFill([
            'created_at' => $created,
            'due_at' => $created->addHours(120),
        ]);

        $clock = new InvestigatorSlaClock;
        $deadline = $clock->deadline($case);

        $this->assertSame(
            $created->addHours(72)->getTimestamp(),
            $deadline->getTimestamp(),
        );
        $this->assertTrue($clock->isBreached(
            (new ExceptionCase)->forceFill([
                'created_at' => now()->subHours(80),
                'due_at' => now()->addHours(40),
            ]),
        ));
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
        $case->setRelation('activities', new Collection([
            (new ExceptionActivity)->forceFill([
                'kind' => ExceptionActivityKind::System,
                'body' => 'DSCSA exception email sent to investigator@example.test.',
                'meta' => ['recipient' => 'investigator@example.test'],
            ]),
        ]));

        $clock = new InvestigatorSlaClock;
        $deadline = $clock->deadline($case);

        $this->assertSame($emailed->getTimestamp(), $deadline->getTimestamp());
        $this->assertFalse($clock->isBreached($case));
    }

    #[Test]
    public function comment_prefixed_like_a_send_does_not_count_as_emailed(): void
    {
        $created = now()->subHours(80);
        $case = (new ExceptionCase)->forceFill([
            'created_at' => $created,
            'due_at' => now()->addHours(40),
        ]);
        $case->setRelation('activities', new Collection([
            (new ExceptionActivity)->forceFill([
                'kind' => ExceptionActivityKind::Comment,
                'body' => 'DSCSA exception email sent to fake@example.test.',
            ]),
        ]));

        $clock = new InvestigatorSlaClock;

        $this->assertTrue($clock->isBreached($case));
        $this->assertSame(
            $created->copy()->addHours(72)->getTimestamp(),
            $clock->deadline($case)->getTimestamp(),
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

    #[Test]
    public function last_supplier_email_at_reads_latest_system_email_activity(): void
    {
        $older = now()->subDays(2);
        $newer = now()->subHour();
        $case = (new ExceptionCase)->forceFill([
            'created_at' => now()->subDays(5),
            'due_at' => null,
        ]);
        $case->setRelation('activities', new Collection([
            (new ExceptionActivity)->forceFill([
                'kind' => ExceptionActivityKind::System,
                'body' => 'DSCSA exception email sent to a@example.test.',
                'created_at' => $older,
            ]),
            (new ExceptionActivity)->forceFill([
                'kind' => ExceptionActivityKind::System,
                'body' => 'DSCSA exception email sent to a@example.test. Automated aging reminder.',
                'created_at' => $newer,
            ]),
            (new ExceptionActivity)->forceFill([
                'kind' => ExceptionActivityKind::Comment,
                'body' => 'DSCSA exception email sent spoof',
                'created_at' => now(),
            ]),
        ]));

        $clock = new InvestigatorSlaClock;
        $this->assertTrue($clock->supplierWasEmailed($case));
        $this->assertSame($newer->getTimestamp(), $clock->lastSupplierEmailAt($case)?->getTimestamp());
    }
}
