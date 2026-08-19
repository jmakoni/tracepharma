<?php

namespace Tests\Unit\Places;

use App\Support\Places\UsState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UsStateTest extends TestCase
{
    #[Test]
    public function normalizes_full_state_names(): void
    {
        $this->assertSame('NJ', UsState::normalize('New Jersey'));
        $this->assertSame('KY', UsState::normalize('kentucky'));
        $this->assertSame('NY', UsState::normalize('New York'));
        $this->assertSame('CA', UsState::normalize(' California '));
    }

    #[Test]
    public function passes_through_already_2_letter_codes(): void
    {
        $this->assertSame('TX', UsState::normalize('TX'));
        $this->assertSame('TX', UsState::normalize('tx'));
    }

    #[Test]
    public function returns_null_for_blank_or_unknown_input(): void
    {
        $this->assertNull(UsState::normalize(null));
        $this->assertNull(UsState::normalize(''));
        $this->assertNull(UsState::normalize('   '));
        $this->assertNull(UsState::normalize('Not A State'));
    }

    #[Test]
    public function resolves_the_inhabited_territories(): void
    {
        $this->assertSame('GU', UsState::normalize('Guam'));
        $this->assertSame('VI', UsState::normalize('Virgin Islands'));
        $this->assertSame('VI', UsState::normalize('U.S. Virgin Islands'));
        $this->assertSame('AS', UsState::normalize('American Samoa'));
        $this->assertSame('MP', UsState::normalize('Northern Mariana Islands'));
        $this->assertSame('PR', UsState::normalize('Puerto Rico'));
    }

    #[Test]
    public function territory_codes_pass_through_and_are_selectable(): void
    {
        foreach (['GU', 'VI', 'AS', 'MP', 'PR', 'DC'] as $code) {
            $this->assertSame($code, UsState::normalize($code));
            $this->assertSame($code, UsState::normalize(strtolower($code)));
            $this->assertArrayHasKey($code, UsState::selectOptions());
            $this->assertContains($code, UsState::codes());
        }
    }

    #[Test]
    public function codes_cover_the_states_dc_and_five_territories(): void
    {
        $codes = UsState::codes();

        $this->assertCount(56, $codes);
        $this->assertSame(array_values(array_unique($codes)), $codes);

        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes);

        // Not a postal code for any state or territory.
        $this->assertNotContains('XX', $codes);
    }
}
