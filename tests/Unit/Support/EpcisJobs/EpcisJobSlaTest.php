<?php

declare(strict_types=1);

namespace Tests\Unit\Support\EpcisJobs;

use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Models\EpcisJob;
use App\Support\EpcisJobs\EpcisJobSla;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisJobSlaTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function is_past_sending_or_processing_sla_when_outbound_job_exceeds_worker_timeout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'UTC'));

        $job = new EpcisJob([
            'kind' => EpcisJobKind::OutboundShipping,
            'status' => EpcisJobStatus::Sending,
            'started_at' => now()->subSeconds(EpcisJobSla::workerTimeoutSeconds(new EpcisJob([
                'kind' => EpcisJobKind::OutboundShipping,
            ])) + EpcisJobSla::GRACE_SECONDS),
        ]);

        $this->assertTrue(EpcisJobSla::isPastSendingOrProcessingSla($job));
    }

    #[Test]
    public function is_past_sending_or_processing_sla_false_when_outbound_job_within_grace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'UTC'));

        $job = new EpcisJob([
            'kind' => EpcisJobKind::OutboundShipping,
            'status' => EpcisJobStatus::Sending,
            'started_at' => now()->subSeconds(120),
        ]);

        $this->assertFalse(EpcisJobSla::isPastSendingOrProcessingSla($job));
    }

    #[Test]
    public function is_past_sending_or_processing_sla_uses_longer_inbound_timeout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'UTC'));

        $withinSla = new EpcisJob([
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Processing,
            'started_at' => now()->subSeconds(500),
        ]);

        $pastSla = new EpcisJob([
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Processing,
            'started_at' => now()->subSeconds(
                EpcisJobSla::workerTimeoutSeconds($withinSla) + EpcisJobSla::GRACE_SECONDS,
            ),
        ]);

        $this->assertFalse(EpcisJobSla::isPastSendingOrProcessingSla($withinSla));
        $this->assertTrue(EpcisJobSla::isPastSendingOrProcessingSla($pastSla));
    }

    #[Test]
    public function is_past_sending_or_processing_sla_false_for_non_active_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'UTC'));

        $job = new EpcisJob([
            'kind' => EpcisJobKind::OutboundShipping,
            'status' => EpcisJobStatus::Queued,
            'started_at' => now()->subHours(2),
        ]);

        $this->assertFalse(EpcisJobSla::isPastSendingOrProcessingSla($job));
    }
}
