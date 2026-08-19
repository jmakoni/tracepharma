<?php

namespace Tests\Unit\Support\Receiving;

use App\Support\Receiving\ReceivingSessionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReceivingSessionStatusTest extends TestCase
{
    #[Test]
    public function known_statuses_map_to_human_labels(): void
    {
        $this->assertSame('Open', ReceivingSessionStatus::label('open'));
        $this->assertSame('In progress', ReceivingSessionStatus::label('in_progress'));
        $this->assertSame('Completed', ReceivingSessionStatus::label('completed'));
        $this->assertSame('Cancelled', ReceivingSessionStatus::label('cancelled'));
    }

    #[Test]
    public function unknown_status_falls_back_to_ucfirst(): void
    {
        $this->assertSame('Abandoned', ReceivingSessionStatus::label('abandoned'));
    }

    #[Test]
    public function null_status_is_unknown(): void
    {
        $this->assertSame('Unknown', ReceivingSessionStatus::label(null));
    }
}
