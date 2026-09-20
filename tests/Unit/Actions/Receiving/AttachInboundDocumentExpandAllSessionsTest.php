<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Receiving;

use App\Actions\Receiving\AttachInboundDocumentToShipment;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class AttachInboundDocumentExpandAllSessionsTest extends TestCase
{
    #[Test]
    public function expand_open_receiving_session_loops_all_live_sessions_oldest_first(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(AttachInboundDocumentToShipment::class))->getFileName() ?: '',
        );

        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString("whereIn('status', ['open', 'in_progress'])", $source);
        $this->assertStringContainsString("orderBy('id')", $source);
        $this->assertStringContainsString('foreach ($sessions as $session)', $source);
        $this->assertStringNotContainsString("orderByDesc('id')\n            ->first()", $source);
    }
}
