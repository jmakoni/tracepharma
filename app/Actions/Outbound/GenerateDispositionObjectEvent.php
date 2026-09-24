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

    public const KIND_DISPENSING = 'dispensing';

    public const KIND_INSPECTING = 'inspecting';

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
        return $this->executeGroup([$epcUri], $kind, $siteId, $settings);
    }

    /**
     * @param  list<string>  $epcUris
     * @param  self::KIND_*  $kind
     * @param  array{sgln_urn?: string, disposition?: string}|null  $settings
     */
    public function executeGroup(array $epcUris, string $kind, ?int $siteId = null, ?array $settings = null): string
    {
        $uris = [];
        foreach ($epcUris as $epcUri) {
            $uri = trim((string) $epcUri);
            if ($uri !== '') {
                $uris[] = $uri;
            }
        }

        if ($uris === []) {
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
            self::KIND_DISPENSING => [
                EpcisAction::Observe,
                'dispensing',
                'dispensed',
            ],
            self::KIND_INSPECTING => [
                EpcisAction::Observe,
                'inspecting',
                'active',
            ],
            default => throw new InvalidArgumentException("Unsupported disposition kind [{$kind}]."),
        };

        $this->assertCandidate->handle(
            epcList: $uris,
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
        $epcXml = '';
        foreach ($uris as $uri) {
            $epcXml .= '                    <epc>'.htmlspecialchars($uri, ENT_XML1)."</epc>\n";
        }
        $actionXml = htmlspecialchars($action->value, ENT_XML1);
        $bizStepXml = htmlspecialchars('urn:epcglobal:cbv:bizstep:'.$bizStep, ENT_XML1);
        $dispositionXml = htmlspecialchars('urn:epcglobal:cbv:disp:'.$disposition, ENT_XML1);
        $ilmdXml = $this->commissioningIlmdXml($kind, $uris);

        return <<<XML
            <ObjectEvent>
                <eventTime>{$eventTime}</eventTime>
                <eventTimeZoneOffset>{$timezoneOffset}</eventTimeZoneOffset>
                <epcList>
{$epcXml}                </epcList>
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
     *
     * @param  list<string>  $epcUris
     */
    private function commissioningIlmdXml(string $kind, array $epcUris): string
    {
        if ($kind !== self::KIND_COMMISSIONING) {
            return '';
        }

        $epc = Epc::query()
            ->whereIn('epc_uri', $epcUris)
            ->where('epc_type', 'sgtin')
            ->first();
        if (! $epc instanceof Epc) {
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
