<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Exceptions;

use App\Support\Exceptions\PdgExceptionNotificationPayload;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PdgExceptionNotificationPayloadTest extends TestCase
{
    #[Test]
    public function payload_class_is_resolvable(): void
    {
        $this->assertTrue(class_exists(PdgExceptionNotificationPayload::class));
        $this->assertTrue(method_exists(PdgExceptionNotificationPayload::class, 'forCase'));
        $this->assertTrue(method_exists(PdgExceptionNotificationPayload::class, 'jsonForCase'));
    }
}
