<?php

namespace Tests\Unit\Support;

use App\Support\Config\SafetyGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The compliance kill-switches (outbound ATP gate, ingest soft gate, TI/TS enforcement,
 * regulatory password gate) must only come down when somebody writes a value that means
 * false. A blank or malformed value is the accident case and has to keep enforcing.
 */
class ComplianceKillSwitchTest extends TestCase
{
    private const KEY = 'TRACEPHARMA_TEST_KILL_SWITCH';

    protected function tearDown(): void
    {
        $this->clearEnv();

        parent::tearDown();
    }

    #[Test]
    public function an_absent_variable_keeps_the_gate_enforcing(): void
    {
        $this->clearEnv();

        $this->assertTrue(SafetyGate::enabled(self::KEY));
    }

    /**
     * `FLAG=` in .env yields an empty string, and `(bool) ''` is false — the cast this
     * replaces disabled enforcement for a line nobody meant as an off switch.
     */
    #[Test]
    #[DataProvider('valuesThatMustNotLiftTheGate')]
    public function a_blank_or_unparseable_value_keeps_the_gate_enforcing(string $value): void
    {
        $this->setEnv($value);

        $this->assertTrue(SafetyGate::enabled(self::KEY));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valuesThatMustNotLiftTheGate(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'unparseable' => ['maybe'],
            'typo' => ['flase'],
        ];
    }

    #[Test]
    #[DataProvider('valuesThatLiftTheGate')]
    public function an_explicit_false_lifts_the_gate(string $value): void
    {
        $this->setEnv($value);

        $this->assertFalse(SafetyGate::enabled(self::KEY));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valuesThatLiftTheGate(): array
    {
        return [
            'false' => ['false'],
            'zero' => ['0'],
            'off' => ['off'],
            'padded false' => [' false '],
        ];
    }

    #[Test]
    public function an_explicit_true_keeps_the_gate_enforcing(): void
    {
        $this->setEnv('true');
        $this->assertTrue(SafetyGate::enabled(self::KEY));

        $this->setEnv('1');
        $this->assertTrue(SafetyGate::enabled(self::KEY));
    }

    #[Test]
    public function a_caller_supplied_default_is_honoured_for_a_blank_value(): void
    {
        $this->setEnv('');

        $this->assertFalse(SafetyGate::enabled(self::KEY, default: false));
    }

    #[Test]
    public function every_shipped_compliance_gate_defaults_to_enforcing(): void
    {
        foreach ([
            'tracepharma.epcis.enforce_atp_outbound_gate',
            'tracepharma.epcis.enforce_atp_soft_gate',
            'tracepharma.epcis.enforce_ts_for_receiving',
            'tracepharma.epcis.require_validated_for_receiving',
        ] as $key) {
            $this->assertTrue(
                (bool) config($key),
                "Expected [{$key}] to enforce by default.",
            );
        }
    }

    private function setEnv(string $value): void
    {
        $_ENV[self::KEY] = $value;
        $_SERVER[self::KEY] = $value;
        putenv(self::KEY.'='.$value);
    }

    private function clearEnv(): void
    {
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        putenv(self::KEY);
    }
}
