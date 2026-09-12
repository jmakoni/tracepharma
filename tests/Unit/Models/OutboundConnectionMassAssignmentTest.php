<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\OutboundConnection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundConnectionMassAssignmentTest extends TestCase
{
    #[Test]
    public function fill_cannot_set_system_flags_but_force_fill_can(): void
    {
        $connection = new OutboundConnection;
        $connection->fill([
            'name' => 'Probe',
            'is_system' => true,
            'system_key' => OutboundConnection::SYSTEM_KEY_EMAIL_ATTACHMENT,
        ]);

        $this->assertSame('Probe', $connection->name);
        $this->assertNull($connection->is_system);
        $this->assertNull($connection->system_key);

        $connection->forceFill([
            'is_system' => true,
            'system_key' => OutboundConnection::SYSTEM_KEY_EMAIL_ATTACHMENT,
        ]);

        $this->assertTrue($connection->is_system);
        $this->assertSame(
            OutboundConnection::SYSTEM_KEY_EMAIL_ATTACHMENT,
            $connection->system_key,
        );
    }
}
