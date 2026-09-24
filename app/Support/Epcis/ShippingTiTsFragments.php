<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Enums\EpcisGuideline;
use App\Support\Epcis\Validation\EpcisCatalogBusinessRules;
use DomainException;

/**
 * DSCSA TI/TS fragments for outbound shipping EPCIS: business transaction
 * references, source/destination parties, the transaction statement, and the
 * SBDH.
 *
 * Shared by the first-send document (GenerateShippingEpcisEvents) and the
 * full-history rebuild ({@see BuildFullHistoryShippingEpcisXml}) so the two
 * never drift on URN or element shapes.
 */
final class ShippingTiTsFragments
{
    public const BTT_PO = 'urn:epcglobal:cbv:btt:po';

    public const BTT_DESADV = 'urn:epcglobal:cbv:btt:desadv';

    public const SDT_OWNING_PARTY = 'urn:epcglobal:cbv:sdt:owning_party';

    public const SDT_LOCATION = 'urn:epcglobal:cbv:sdt:location';

    public const LEGAL_NOTICE = 'Seller has complied with each applicable subsection of FDCA Sec. 581(27)(A)-(G).';

    /**
     * The PO is referenced against the buyer's GLN and the ASN against the
     * seller's, per the GS1 US Implementation Guideline.
     *
     * @return list<array{type_uri: string, value: string}>
     */
    public static function bizTransactions(
        ?string $po,
        ?string $asn,
        ?string $destOwningGln,
        ?string $sourceOwningGln,
    ): array {
        $transactions = [];

        if (filled($po) && filled($destOwningGln)) {
            $transactions[] = [
                'type_uri' => self::BTT_PO,
                'value' => self::bizTransactionUrn((string) $destOwningGln, (string) $po),
            ];
        }

        if (filled($asn) && filled($sourceOwningGln)) {
            $transactions[] = [
                'type_uri' => self::BTT_DESADV,
                'value' => self::bizTransactionUrn((string) $sourceOwningGln, (string) $asn),
            ];
        }

        return $transactions;
    }

    public static function bizTransactionUrn(string $gln, string $reference): string
    {
        return 'urn:epcglobal:cbv:bt:'.$gln.':'.$reference;
    }

    /**
     * Empty string when neither reference can be authored, so the event omits the
     * element rather than emitting an invalid empty list.
     */
    /**
     * @return list<array{type: string, bizTransaction: string}>
     */
    public static function bizTransactionListJson(
        ?string $po,
        ?string $asn,
        ?string $destOwningGln,
        ?string $sourceOwningGln,
    ): array {
        $transactions = [];

        foreach (self::bizTransactions($po, $asn, $destOwningGln, $sourceOwningGln) as $transaction) {
            $transactions[] = [
                'type' => $transaction['type_uri'],
                'bizTransaction' => $transaction['value'],
            ];
        }

        return $transactions;
    }

    public static function bizTransactionListXml(
        ?string $po,
        ?string $asn,
        ?string $destOwningGln,
        ?string $sourceOwningGln,
    ): string {
        $transactions = self::bizTransactions($po, $asn, $destOwningGln, $sourceOwningGln);

        if ($transactions === []) {
            return '';
        }

        $items = '';
        foreach ($transactions as $transaction) {
            $items .= '          <bizTransaction type="'.self::e($transaction['type_uri']).'">'
                .self::e($transaction['value'])
                ."</bizTransaction>\n";
        }

        return
            "        <bizTransactionList>\n".
            $items.
            "        </bizTransactionList>\n";
    }

    /**
     * EPCIS 2.0 JSON-LD source/destination parties (top-level on the event).
     *
     * @return array{
     *     sourceList: list<array{type: string, source: string}>,
     *     destinationList: list<array{type: string, destination: string}>
     * }
     */
    public static function sourceDestinationListsJson(
        string $sourceOwningSgln,
        string $sourceLocationSgln,
        string $destOwningSgln,
        string $destLocationSgln,
    ): array {
        return [
            'sourceList' => [
                [
                    'type' => self::SDT_OWNING_PARTY,
                    'source' => $sourceOwningSgln,
                ],
                [
                    'type' => self::SDT_LOCATION,
                    'source' => $sourceLocationSgln,
                ],
            ],
            'destinationList' => [
                [
                    'type' => self::SDT_OWNING_PARTY,
                    'destination' => $destOwningSgln,
                ],
                [
                    'type' => self::SDT_LOCATION,
                    'destination' => $destLocationSgln,
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $indirectPurchaseEpcs
     */
    public static function sourceDestinationExtensionXml(
        string $sourceOwningSgln,
        string $sourceLocationSgln,
        string $destOwningSgln,
        string $destLocationSgln,
        ?string $directPurchaseStatement = null,
        EpcisGuideline $guideline = EpcisGuideline::R13,
        string $directPurchaseQualifier = 'ENTIRELY_DIRECT',
        array $indirectPurchaseEpcs = [],
        ?string $prevWholesalerStatement = null,
        string $prevWholesalerQualifier = 'ENTIRELY_DIRECT',
    ): string {
        // Core EPCIS 1.2 ObjectEventExtensionType allows sourceList/destinationList
        // then optional nested <extension> (##local only). GS1 US HC directPurchase
        // is namespaced (##other) and must follow the ObjectEvent <extension> block.
        $xml =
            "        <extension>\n".
            "          <sourceList>\n".
            '            <source type="'.self::e(self::SDT_OWNING_PARTY).'">'.self::e($sourceOwningSgln)."</source>\n".
            '            <source type="'.self::e(self::SDT_LOCATION).'">'.self::e($sourceLocationSgln)."</source>\n".
            "          </sourceList>\n".
            "          <destinationList>\n".
            '            <destination type="'.self::e(self::SDT_OWNING_PARTY).'">'.self::e($destOwningSgln)."</destination>\n".
            '            <destination type="'.self::e(self::SDT_LOCATION).'">'.self::e($destLocationSgln)."</destination>\n".
            "          </destinationList>\n".
            "        </extension>\n";

        if ($directPurchaseStatement !== null && $directPurchaseStatement !== '') {
            $xml .= self::directPurchaseXml(
                $directPurchaseStatement,
                $guideline,
                $directPurchaseQualifier,
                $indirectPurchaseEpcs,
            );
        }

        if ($guideline === EpcisGuideline::R13 && $prevWholesalerStatement !== null && $prevWholesalerStatement !== '') {
            $xml .= self::receivedPrevWholesalerXml($prevWholesalerStatement, $prevWholesalerQualifier);
        }

        return $xml;
    }

    /**
     * @param  list<string>  $indirectPurchaseEpcs
     */
    public static function directPurchaseXml(
        string $statement,
        EpcisGuideline $guideline = EpcisGuideline::R13,
        string $qualifier = 'ENTIRELY_DIRECT',
        array $indirectPurchaseEpcs = [],
    ): string {
        if ($guideline === EpcisGuideline::R12) {
            return "        <gs1ushc:directPurchase>true</gs1ushc:directPurchase>\n";
        }

        $qualifier = strtoupper($qualifier);
        $indirectXml = '';
        if ($qualifier === 'PARTIALLY_DIRECT' && $indirectPurchaseEpcs !== []) {
            $indirectXml = "          <gs1ushc:indirectPurchaseEPCs>\n";
            foreach ($indirectPurchaseEpcs as $uri) {
                $uri = trim((string) $uri);
                if ($uri === '') {
                    continue;
                }
                $indirectXml .= '            <epc>'.self::e($uri)."</epc>\n";
            }
            $indirectXml .= "          </gs1ushc:indirectPurchaseEPCs>\n";
        }

        return
            '        <gs1ushc:directPurchase qualifier="'.self::e($qualifier)."\">\n".
            '          <gs1ushc:directPurchaseStatement>'.self::e($statement)."</gs1ushc:directPurchaseStatement>\n".
            $indirectXml.
            "        </gs1ushc:directPurchase>\n";
    }

    public static function receivedPrevWholesalerXml(
        string $statement,
        string $qualifier = 'ENTIRELY_DIRECT',
    ): string {
        $qualifier = strtoupper($qualifier);

        return
            '        <gs1ushc:receivedDirectPurchaseFromPrevWhlsDist qualifier="'.self::e($qualifier)."\">\n".
            '          <gs1ushc:receivedDirectPurchaseFromPrevWhlsDistStatement>'.self::e($statement)."</gs1ushc:receivedDirectPurchaseFromPrevWhlsDistStatement>\n".
            "        </gs1ushc:receivedDirectPurchaseFromPrevWhlsDist>\n";
    }

    public static function transactionDateXml(string $date, string $indent = '        '): string
    {
        if ($date === '') {
            return '';
        }

        return $indent.'<gs1ushc:transactionDate>'.self::e($date)."</gs1ushc:transactionDate>\n";
    }

    /**
     * @return array<string, string>
     */
    public static function transactionDateExtensionJson(string $date): array
    {
        if ($date === '') {
            return [];
        }

        return ['gs1ushc:transactionDate' => $date];
    }

    public static function guidelineVersionXml(EpcisGuideline $guideline, string $indent = '    '): string
    {
        if ($guideline !== EpcisGuideline::R13) {
            return '';
        }

        return $indent."<gs1ushc:guidelineVersion>GS1 US DSCSA R1.3</gs1ushc:guidelineVersion>\n";
    }

    /**
     * @param  list<string>  $indirectPurchaseEpcs
     * @return array<string, mixed>
     */
    public static function directPurchaseExtensionJson(
        string $statement,
        string $qualifier = 'ENTIRELY_DIRECT',
        array $indirectPurchaseEpcs = [],
    ): array {
        $qualifier = strtoupper($qualifier);
        $payload = [
            'qualifier' => $qualifier,
            'directPurchaseStatement' => $statement,
        ];

        if ($qualifier === 'PARTIALLY_DIRECT' && $indirectPurchaseEpcs !== []) {
            $payload['indirectPurchaseEPCs'] = array_values(array_filter(
                array_map(static fn (string $uri): string => trim($uri), $indirectPurchaseEpcs),
            ));
        }

        return ['directPurchase' => $payload];
    }

    /**
     * @return array<string, mixed>
     */
    public static function receivedPrevWholesalerExtensionJson(
        string $statement,
        string $qualifier = 'ENTIRELY_DIRECT',
    ): array {
        return [
            'receivedDirectPurchaseFromPrevWhlsDist' => [
                'qualifier' => strtoupper($qualifier),
                'receivedDirectPurchaseFromPrevWhlsDistStatement' => $statement,
            ],
        ];
    }

    /**
     * Indented for EPCISHeader (or nested under header extension).
     *
     * @param  non-empty-string  $indent
     */
    public static function dscsaTransactionStatementXml(string $indent = '    '): string
    {
        $child = $indent.'  ';

        return
            $indent."<gs1ushc:dscsaTransactionStatement>\n".
            $child."<gs1ushc:affirmTransactionStatement>true</gs1ushc:affirmTransactionStatement>\n".
            $child.'<gs1ushc:legalNotice>'.self::e(self::LEGAL_NOTICE)."</gs1ushc:legalNotice>\n".
            $indent."</gs1ushc:dscsaTransactionStatement>\n";
    }

    /**
     * GS1 US HC drop-shipment indicator. Always emits true|false so partners can
     * distinguish an explicit non-drop ship from a missing element. Inbound catalog
     * rule {@see EpcisCatalogBusinessRules} string-scans for `dropShipment`.
     *
     * @param  non-empty-string  $indent
     */
    public static function dropShipmentIndicatorXml(
        bool $isDropShipment,
        string $indent = '    ',
        EpcisGuideline $guideline = EpcisGuideline::R13,
    ): string {
        if ($guideline !== EpcisGuideline::R13) {
            return '';
        }

        $value = $isDropShipment ? 'true' : 'false';

        return $indent.'<gs1ushc:dropShipment>'.$value."</gs1ushc:dropShipment>\n";
    }

    /**
     * GS1 US HC drop-shipment indicator on an EPCIS 2.0 JSON-LD document envelope.
     */
    public static function withDropShipmentDocumentField(string $json, bool $isDropShipment = true): string
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $document['gs1ushc:dropShipment'] = $isDropShipment;

        $encoded = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new DomainException('Unable to encode drop-shipment EPCIS 2.0 JSON-LD document.');
        }

        return $encoded."\n";
    }

    /**
     * GS1 US HC DSCSA transaction statement on an EPCIS 2.0 JSON-LD document envelope.
     */
    public static function withDscsaTransactionStatementDocumentField(string $json): string
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $document['gs1ushc:dscsaTransactionStatement'] = [
            'gs1ushc:affirmTransactionStatement' => true,
            'gs1ushc:legalNotice' => self::LEGAL_NOTICE,
        ];

        $encoded = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new DomainException('Unable to encode DSCSA transaction statement on EPCIS 2.0 JSON-LD document.');
        }

        return $encoded."\n";
    }

    /**
     * Fail closed when a drop-ship ship order's payload lacks the indicator
     * inbound validation would accept (stripos for `dropShipment` in XML or JSON).
     */
    public static function assertDropShipmentEmitted(
        bool $isDropShipment,
        string $payload,
        EpcisGuideline $guideline = EpcisGuideline::R13,
    ): void {
        if (! $isDropShipment) {
            return;
        }

        if ($guideline !== EpcisGuideline::R13) {
            throw new DomainException(
                'Drop shipment requires GS1 US DSCSA guideline R1.3 on the trading partner.',
            );
        }

        if (stripos($payload, 'dropShipment') === false) {
            throw new DomainException(
                'Drop-shipment ship order requires a dropShipment indicator in outbound EPCIS, but none was emitted.',
            );
        }
    }

    /**
     * Indented for a direct child of EPCISHeader.
     *
     * @param  string  $creationDate  already formatted as xs:dateTime
     */
    public static function sbdhXml(
        string $senderGln,
        string $receiverGln,
        string $instanceId,
        string $creationDate,
        EpcisGuideline $guideline = EpcisGuideline::R12,
    ): string {
        $authority = $guideline->sbdhAuthority();

        return
            "    <sbdh:StandardBusinessDocumentHeader>\n".
            "      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>\n".
            "      <sbdh:Sender>\n".
            '        <sbdh:Identifier Authority="'.$authority.'">'.self::e($senderGln)."</sbdh:Identifier>\n".
            "      </sbdh:Sender>\n".
            "      <sbdh:Receiver>\n".
            '        <sbdh:Identifier Authority="'.$authority.'">'.self::e($receiverGln)."</sbdh:Identifier>\n".
            "      </sbdh:Receiver>\n".
            "      <sbdh:DocumentIdentification>\n".
            "        <sbdh:Standard>EPCglobal</sbdh:Standard>\n".
            "        <sbdh:TypeVersion>1.0</sbdh:TypeVersion>\n".
            '        <sbdh:InstanceIdentifier>'.self::e($instanceId)."</sbdh:InstanceIdentifier>\n".
            "        <sbdh:Type>Events</sbdh:Type>\n".
            '        <sbdh:CreationDateAndTime>'.self::e($creationDate)."</sbdh:CreationDateAndTime>\n".
            "      </sbdh:DocumentIdentification>\n".
            "    </sbdh:StandardBusinessDocumentHeader>\n";
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
