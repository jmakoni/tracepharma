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
     * Transport timeout files an investigation case so the unit is not auto-released,
     * but it is not a suspect verdict — do not open a hold from timeout alone.
     */
    #[Test]
    public function transport_failures_open_a_case_without_a_hold(): void
    {
        foreach (['unavailable', 'error'] as $status) {
            $this->assertTrue(
                $this->shouldOpenException($status),
                $status.' must open a case so the scan is not auto-released.',
            );
            $this->assertFalse(
                $this->shouldOpenHold($status),
                $status.' must not quarantine on transport timeout.',
            );
        }

        foreach (['failed', 'suspect'] as $status) {
            $this->assertTrue(
                $this->shouldOpenException($status),
                $status.' is a responder verdict and must open a case.',
            );
            $this->assertTrue(
                $this->shouldOpenHold($status),
                $status.' must open a hold, including identity-only fails.',
            );
        }

        foreach (['verified', 'deferred'] as $status) {
            $this->assertFalse($this->shouldOpenException($status));
            $this->assertFalse($this->shouldOpenHold($status));
        }
    }

    private function shouldOpenException(string $status): bool
    {
        $action = (new \ReflectionClass(RunProductVerification::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RunProductVerification::class, 'shouldOpenException');

        return (bool) $method->invoke($action, $status);
    }

    private function shouldOpenHold(string $status): bool
    {
        $action = (new \ReflectionClass(RunProductVerification::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RunProductVerification::class, 'shouldOpenHold');

        return (bool) $method->invoke($action, $status);
    }
}
