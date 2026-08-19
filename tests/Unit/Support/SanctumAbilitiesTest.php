<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SanctumAbilities;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SanctumAbilitiesTest extends TestCase
{
    #[Test]
    public function validate_for_token_creation_rejects_wildcard_ability(): void
    {
        $this->expectException(ValidationException::class);

        SanctumAbilities::validateForTokenCreation(['*']);
    }

    #[Test]
    public function validate_for_token_creation_rejects_unknown_abilities(): void
    {
        $this->expectException(ValidationException::class);

        SanctumAbilities::validateForTokenCreation(['view', 'not-a-real-ability']);
    }

    #[Test]
    public function validate_for_token_creation_requires_at_least_one_ability(): void
    {
        $this->expectException(ValidationException::class);

        SanctumAbilities::validateForTokenCreation([]);
    }

    #[Test]
    public function validate_for_token_creation_accepts_vrs_dispense_check_ability(): void
    {
        $abilities = SanctumAbilities::validateForTokenCreation([
            SanctumAbilities::VRS_DISPENSE_CHECK,
        ]);

        $this->assertSame([SanctumAbilities::VRS_DISPENSE_CHECK], $abilities);
    }

    #[Test]
    public function validate_for_token_creation_accepts_allowlisted_abilities(): void
    {
        $abilities = SanctumAbilities::validateForTokenCreation([
            SanctumAbilities::EPCIS_VIEW,
            SanctumAbilities::EPCIS_TRANSMIT,
        ]);

        $this->assertSame([
            SanctumAbilities::EPCIS_VIEW,
            SanctumAbilities::EPCIS_TRANSMIT,
        ], $abilities);
    }

    #[Test]
    public function options_exclude_unused_crud_abilities(): void
    {
        $options = SanctumAbilities::options();

        $this->assertArrayNotHasKey('create', $options);
        $this->assertArrayNotHasKey('view', $options);
        $this->assertArrayNotHasKey('update', $options);
        $this->assertArrayNotHasKey('delete', $options);
        $this->assertArrayHasKey(SanctumAbilities::EPCIS_UPLOAD, $options);
    }
}
