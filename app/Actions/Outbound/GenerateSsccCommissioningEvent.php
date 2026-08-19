<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Domain\Epcis\Enums\EpcisAction;
use App\Domain\Gs1\Sscc18;
use App\Domain\Gs1\SsccUri;
use App\Models\SsccLabel;
use InvalidArgumentException;

final class GenerateSsccCommissioningEvent
{
    public function __construct(
        private readonly ResolveSsccAuthoredLocation $resolveLocation,
        private readonly AssertAuthoredObjectEventCandidate $assertCandidate,
    ) {}

    /**
     * @param  array{sgln_urn?: string}|null  $settings
     */
    public function execute(SsccLabel $label, ?int $siteId = null, ?array $settings = null): string
    {
        $sscc18 = Sscc18::fromDigits((string) $label->sscc_18);

        $ssccUrn = trim((string) $label->sscc_urn);
        if ($ssccUrn === '') {
            throw new InvalidArgumentException('SSCC label is missing a valid sscc_urn for commissioning.');
        }

        $ssccUri = SsccUri::fromUrn($ssccUrn);

        if ($ssccUri->sscc()->toString() !== $sscc18->toString()) {
            throw new InvalidArgumentException('SSCC label sscc_urn does not match sscc_18 for commissioning.');
        }

        $candidate = $this->assertCandidate->handle(
            epcList: [$ssccUri->toString()],
            action: EpcisAction::Add,
            bizStep: 'commissioning',
            disposition: 'active',
        );

        $canonicalUri = $candidate->epcList[0] ?? $ssccUri->toString();

        $sglnUrn = htmlspecialchars($this->resolveSglnUrn($settings ?? [], $siteId), ENT_XML1);
        $eventTime = htmlspecialchars(now()->toIso8601String(), ENT_XML1);
        $epc = htmlspecialchars($canonicalUri, ENT_XML1);

        return <<<XML
            <ObjectEvent>
                <eventTime>{$eventTime}</eventTime>
                <eventTimeZoneOffset>+00:00</eventTimeZoneOffset>
                <epcList>
                    <epc>{$epc}</epc>
                </epcList>
                <action>ADD</action>
                <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
                <disposition>urn:epcglobal:cbv:disp:active</disposition>
                <readPoint><id>{$sglnUrn}</id></readPoint>
                <bizLocation><id>{$sglnUrn}</id></bizLocation>
            </ObjectEvent>
XML;
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
