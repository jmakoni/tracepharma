<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Domain\Epcis\Enums\EpcisAction;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Site;
use App\Support\Epcis\AuthoredEventTimezone;
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
                ...$this->decommissioningStepAndDisposition($settings),
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
        $site = $siteId !== null ? Site::query()->find($siteId) : null;
        $timezoneOffset = htmlspecialchars(
            AuthoredEventTimezone::offsetForSite($site instanceof Site ? $site : null),
            ENT_XML1,
        );
        $eventTime = htmlspecialchars(now()->toIso8601String(), ENT_XML1);
        $epc = htmlspecialchars($epcUri, ENT_XML1);
        $actionXml = htmlspecialchars($action->value, ENT_XML1);
        $bizStepXml = htmlspecialchars('urn:epcglobal:cbv:bizstep:'.$bizStep, ENT_XML1);
        $dispositionXml = htmlspecialchars('urn:epcglobal:cbv:disp:'.$disposition, ENT_XML1);
        $ilmdXml = $this->commissioningIlmdXml($kind, $epcUri);

        return <<<XML
            <ObjectEvent>
                <eventTime>{$eventTime}</eventTime>
                <eventTimeZoneOffset>{$timezoneOffset}</eventTimeZoneOffset>
                <epcList>
                    <epc>{$epc}</epc>
                </epcList>
                <action>{$actionXml}</action>
                <bizStep>{$bizStepXml}</bizStep>
                <disposition>{$dispositionXml}</disposition>
                <readPoint><id>{$sglnUrn}</id></readPoint>
                <bizLocation><id>{$sglnUrn}</id></bizLocation>
{$ilmdXml}            </ObjectEvent>
XML;
    }

    /**
     * @param  array{sgln_urn?: string}|null  $settings
     */
    public function resolveLocationUrn(?array $settings, ?int $siteId): string
    {
        return $this->resolveSglnUrn($settings ?? [], $siteId);
    }

    /**
     * @param  array{disposition?: string}|null  $settings
     * @return array{0: string, 1: string}
     */
    public function decommissioningStepAndDisposition(?array $settings): array
    {
        $disposition = $this->resolveDispositionLocal($settings['disposition'] ?? null, 'inactive');

        return [
            $disposition === 'destroyed' ? 'destroying' : 'decommissioning',
            $disposition,
        ];
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

    /**
     * Commission-all SGTINs must carry CBV MDA lot/expiry when ILMD is on the EPC.
     */
    private function commissioningIlmdXml(string $kind, string $epcUri): string
    {
        if ($kind !== self::KIND_COMMISSIONING) {
            return '';
        }

        $epc = Epc::query()->where('epc_uri', $epcUri)->first();
        if (! $epc instanceof Epc || $epc->epc_type !== 'sgtin') {
            return '';
        }

        $ilmd = $epc->ilmd;
        if (! $ilmd instanceof EpcIlmd) {
            return '';
        }

        $lot = trim((string) ($ilmd->lot_number ?? ''));
        $expiry = $ilmd->expiry_date?->format('Y-m-d') ?? '';

        if ($lot === '' && $expiry === '') {
            return '';
        }

        $fields = '';
        if ($lot !== '') {
            $fields .= '                    <cbvmda:lotNumber>'.htmlspecialchars($lot, ENT_XML1)."</cbvmda:lotNumber>\n";
        }
        if ($expiry !== '') {
            $fields .= '                    <cbvmda:itemExpirationDate>'.htmlspecialchars($expiry, ENT_XML1)."</cbvmda:itemExpirationDate>\n";
        }

        return <<<XML
                <extension>
                    <ilmd xmlns:cbvmda="urn:epcglobal:cbv:mda">
{$fields}                    </ilmd>
                </extension>

XML;
    }
}
