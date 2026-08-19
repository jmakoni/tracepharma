<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidGln;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ValidGlnTest extends TestCase
{
    #[Test]
    public function it_accepts_a_13_digit_gln_with_a_valid_check_digit(): void
    {
        $this->assertSame([], $this->failures('0614141000005'));
        $this->assertSame([], $this->failures('0301160000009'));
        $this->assertSame([], $this->failures('0096295000009'));
    }

    #[Test]
    public function it_rejects_a_bad_check_digit(): void
    {
        $failures = $this->failures('0614141000006');

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('check digit', $failures[0]);
    }

    #[Test]
    public function it_rejects_anything_that_is_not_13_digits(): void
    {
        foreach (['06141410000', '06141410000051', 'GLN0614141000', '0614141O00005'] as $value) {
            $failures = $this->failures($value);

            $this->assertCount(1, $failures, "Expected {$value} to be rejected.");
            $this->assertStringContainsString('13 digits', $failures[0]);
        }
    }

    #[Test]
    public function blank_passes_so_optional_fields_stay_optional(): void
    {
        $this->assertSame([], $this->failures(null));
        $this->assertSame([], $this->failures(''));
        $this->assertSame([], $this->failures('   '));
    }

    #[Test]
    public function it_normalizes_separators_only_when_the_gln_is_real(): void
    {
        $this->assertSame('0614141000005', ValidGln::normalize('0614 141-000005'));
        $this->assertSame('0614141000005', ValidGln::normalize('0614141000005'));

        $this->assertNull(ValidGln::normalize('0614141000006'));
        $this->assertNull(ValidGln::normalize('06141410000'));
        $this->assertNull(ValidGln::normalize(null));
        $this->assertNull(ValidGln::normalize(''));
        $this->assertNull(ValidGln::normalize([]));
    }

    /**
     * @return list<string>
     */
    private function failures(mixed $value): array
    {
        $failures = [];

        (new ValidGln)->validate('gln', $value, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        return $failures;
    }
}
