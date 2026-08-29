<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Domain\Epcis\Enums\EpcisAction;
use InvalidArgumentException;

/**
 * Build one ObjectEvent XML fragment for commissioning, decommissioning, or returning.
 */
final class GenerateDispositionObjectEvent
{
    public const KIND_COMMISSIONING = 'commissioning';

    public const KIND_DECOMMISSIONING = 'decommissioning';

    public const KIND_RETURNING = 'returning';

    public function __construct(
        private readonly ResolveSsccAuthoredLocation $resolveLocation,
        private readonly AssertAuthoredObjectEventCandidate $assertCandidate,
    ) {}

    /**
     * @param  self::KIND_*  $kind
     * @param  array{sgln_urn?: string, disposition?: string}|null  $settings
     */
    public function execute(string $epcUri, string $kind, ?int $siteId = null, ?array $settings = null): string
    {
        $epcUri = trim($epcUri);
        if ($epcUri === '') {
            throw new InvalidArgumentException('EPC URI is required for disposition ObjectEvent.');
        }

        $settings ??= [];

        [$action, $bizStep, $disposition] = match ($kind) {
            self::KIND_COMMISSIONING => [
                EpcisAction::Add,
                'commissioning',
                'active',
            ],
            self::KIND_DECOMMISSIONING => [
                EpcisAction::Delete,
                'decommissioning',
                $this->resolveDispositionLocal($settings['disposition'] ?? null, 'inactive'),
            ],
            self::KIND_RETURNING => [
                EpcisAction::Observe,
                'returning',
                'returned',
            ],
            default => throw new InvalidArgumentException("Unsupported disposition kind [{$kind}]."),
        };

        $this->assertCandidate->handle(
            epcList: [$epcUri],
            action: $action,
            bizStep: $bizStep,
            disposition: $disposition,
        );

        $sglnUrn = htmlspecialchars($this->resolveSglnUrn($settings, $siteId), ENT_XML1);
        $eventTime = htmlspecialchars(now()->toIso8601String(), ENT_XML1);
        $epc = htmlspecialchars($epcUri, ENT_XML1);
        $actionXml = htmlspecialchars($action->value, ENT_XML1);
        $bizStepXml = htmlspecialchars('urn:epcglobal:cbv:bizstep:'.$bizStep, ENT_XML1);
        $dispositionXml = htmlspecialchars('urn:epcglobal:cbv:disp:'.$disposition, ENT_XML1);

        return <<<XML
            <ObjectEvent>
                <eventTime>{$eventTime}</eventTime>
                <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
                <epcList>
                    <epc>{$epc}</epc>
                </epcList>
                <action>{$actionXml}</action>
                <bizStep>{$bizStepXml}</bizStep>
                <disposition>{$dispositionXml}</disposition>
                <readPoint><id>{$sglnUrn}</id></readPoint>
                <bizLocation><id>{$sglnUrn}</id></bizLocation>
            </ObjectEvent>
XML;
    }

    /**
     * @param  array{sgln_urn?: string}|null  $settings
     */
    public function resolveLocationUrn(?array $settings, ?int $siteId): string
    {
        return $this->resolveSglnUrn($settings ?? [], $siteId);
    }

    private function resolveDispositionLocal(?string $disposition, string $default): string
    {
        $value = strtolower(trim((string) $disposition));
        if ($value === '') {
            return $default;
        }

        if (str_contains($value, ':')) {
            $value = (string) str($value)->afterLast(':');
        }

        $value = trim($value);

        return $value !== '' ? $value : $default;
    }

    /**
     * @param  array{sgln_urn?: string}  $settings
     */
    private function resolveSglnUrn(array $settings, ?int $siteId): string
    {
        $sglnUrn = trim((string) ($settings['sgln_urn'] ?? ''));

        if ($sglnUrn !== '') {
            return $sglnUrn;
        }

        return $this->resolveLocation->handle($siteId)['sgln_urn'];
    }
}
