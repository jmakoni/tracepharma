<?php

declare(strict_types=1);

namespace App\Domain\Epcis\Validation;

use App\Domain\Epcis\Validation\Contracts\ValidationStage;
use App\Domain\Epcis\Validation\Stages\BusinessRulesValidationStage;
use App\Domain\Epcis\Validation\Stages\Gs1IdentityValidationStage;
use App\Domain\Epcis\Validation\Stages\SyntaxValidationStage;

/**
 * Hard gate: syntax → GS1 schema → business rules. First failure wins; no partial commit.
 */
final class ValidationPipeline
{
    /**
     * @param  list<ValidationStage>  $stages
     */
    public function __construct(
        private readonly array $stages,
    ) {}

    public static function default(): self
    {
        return new self([
            new SyntaxValidationStage,
            new Gs1IdentityValidationStage,
            new BusinessRulesValidationStage,
        ]);
    }

    public function validate(ValidationContext $context): ValidationResult
    {
        foreach ($this->stages as $stage) {
            $failure = $stage->validate($context);
            if ($failure !== null) {
                return ValidationResult::fromFailure($failure);
            }
        }

        return ValidationResult::passed();
    }
}
