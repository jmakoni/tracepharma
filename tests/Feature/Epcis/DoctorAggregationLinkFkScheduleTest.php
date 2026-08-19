<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DoctorAggregationLinkFkScheduleTest extends TestCase
{
    #[Test]
    public function aggregation_link_fk_doctor_is_scheduled_daily_with_alert(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('tracepharma:doctor-aggregation-link-fk --alert')
            ->assertSuccessful();

        $event = $this->scheduledEvent();

        $this->assertSame('0 5 * * *', $event->expression);
        $this->assertSame('aggregation-link-fk-doctor', $event->description);
        $this->assertStringContainsString(
            'tracepharma:doctor-aggregation-link-fk',
            (string) ($event->command ?? ''),
        );
        $this->assertStringContainsString(
            '--alert',
            (string) ($event->command ?? ''),
        );
        $this->assertStringNotContainsString(
            '--fix',
            (string) ($event->command ?? ''),
        );
    }

    private function scheduledEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === 'aggregation-link-fk-doctor');

        $this->assertNotNull($event, 'aggregation-link-fk-doctor is not scheduled.');

        return $event;
    }
}
