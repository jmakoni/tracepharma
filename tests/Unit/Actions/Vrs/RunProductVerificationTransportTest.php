<?php

namespace Tests\Unit\Actions\Vrs;

use App\Actions\Vrs\RunProductVerification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunProductVerificationTransportTest extends TestCase
{
    #[Test]
    public function unreachable_and_faulting_vrs_count_as_transport_failures(): void
    {
        $this->assertTrue(RunProductVerification::isTransportFailure('unavailable'));
        $this->assertTrue(RunProductVerification::isTransportFailure('error'));
    }

    #[Test]
    public function a_returned_verdict_is_never_a_transport_failure(): void
    {
        foreach (['verified', 'failed', 'suspect', 'deferred'] as $status) {
            $this->assertFalse(
                RunProductVerification::isTransportFailure($status),
                $status.' is a VRS verdict, not a transport failure.',
            );
        }
    }

    /**
     * DSCSA: being unable to reach the VRS is not evidence that product is suspect, so a
     * transport failure must not open a High severity case or quarantine the EPC.
     */
    #[Test]
    public function transport_failures_do_not_open_a_verification_exception(): void
    {
        foreach (['unavailable', 'error'] as $status) {
            $this->assertFalse(
                $this->shouldOpenException($status),
                $status.' must not open a quarantine case.',
            );
        }

        foreach (['failed', 'suspect'] as $status) {
            $this->assertTrue(
                $this->shouldOpenException($status),
                $status.' is a responder verdict and must open a case.',
            );
        }

        foreach (['verified', 'deferred'] as $status) {
            $this->assertFalse($this->shouldOpenException($status));
        }
    }

    private function shouldOpenException(string $status): bool
    {
        $action = (new \ReflectionClass(RunProductVerification::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RunProductVerification::class, 'shouldOpenException');

        return (bool) $method->invoke($action, $status);
    }
}
