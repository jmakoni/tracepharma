<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArchiveAgedEpcisEventsScheduleTest extends TestCase
{
    #[Test]
    public function epcis_archive_events_is_scheduled_daily_without_dry_run(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('tracepharma:epcis-archive-events')
            ->assertSuccessful();

        $event = $this->scheduledEvent();

        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('epcis-archive-events', $event->description);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString(
            'tracepharma:epcis-archive-events',
            (string) ($event->command ?? ''),
        );
        $this->assertStringNotContainsString(
            '--dry-run',
            (string) ($event->command ?? ''),
        );
    }

    private function scheduledEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === 'epcis-archive-events');

        $this->assertNotNull($event, 'epcis-archive-events is not scheduled.');

        return $event;
    }
}
