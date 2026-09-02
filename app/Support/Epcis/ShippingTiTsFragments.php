<?php

declare(strict_types=1);

namespace App\Support\Epcis;

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

    public static function sourceDestinationExtensionXml(
        string $sourceOwningSgln,
        string $sourceLocationSgln,
        string $destOwningSgln,
        string $destLocationSgln,
        ?string $directPurchaseStatement = null,
    ): string {
        $inner =
            "          <sourceList>\n".
            '            <source type="'.self::e(self::SDT_OWNING_PARTY).'">'.self::e($sourceOwningSgln)."</source>\n".
            '            <source type="'.self::e(self::SDT_LOCATION).'">'.self::e($sourceLocationSgln)."</source>\n".
            "          </sourceList>\n".
            "          <destinationList>\n".
            '            <destination type="'.self::e(self::SDT_OWNING_PARTY).'">'.self::e($destOwningSgln)."</destination>\n".
            '            <destination type="'.self::e(self::SDT_LOCATION).'">'.self::e($destLocationSgln)."</destination>\n".
            "          </destinationList>\n";

        if ($directPurchaseStatement !== null && $directPurchaseStatement !== '') {
            $inner .= "          <extension>\n".
                self::directPurchaseXml($directPurchaseStatement).
                "          </extension>\n";
        }

        return
            "        <extension>\n".
            $inner.
            "        </extension>\n";
    }

    public static function directPurchaseXml(string $statement): string
    {
        return
            "          <gs1ushc:directPurchase qualifier=\"ENTIRELY_DIRECT\">\n".
            '            <gs1ushc:directPurchaseStatement>'.self::e($statement)."</gs1ushc:directPurchaseStatement>\n".
            "          </gs1ushc:directPurchase>\n";
    }

    /**
     * @return array<string, mixed>
     */
    public static function directPurchaseExtensionJson(string $statement): array
    {
        return [
            'directPurchase' => [
                'qualifier' => 'ENTIRELY_DIRECT',
                'directPurchaseStatement' => $statement,
            ],
        ];
    }

    /**
     * Indented for a direct child of EPCISHeader.
     */
    public static function dscsaTransactionStatementXml(): string
    {
        return
            "    <gs1ushc:dscsaTransactionStatement>\n".
            "      <gs1ushc:affirmTransactionStatement>true</gs1ushc:affirmTransactionStatement>\n".
            '      <gs1ushc:legalNotice>'.self::e(self::LEGAL_NOTICE)."</gs1ushc:legalNotice>\n".
            "    </gs1ushc:dscsaTransactionStatement>\n";
    }

    /**
     * GS1 US HC drop-shipment indicator. Inbound catalog rule
     * {@see EpcisCatalogBusinessRules} string-scans
     * for `dropShipment`; emit only when the ship order is flagged.
     *
     * Empty string when unflagged so the header omits the element.
     */
    public static function dropShipmentIndicatorXml(bool $isDropShipment): string
    {
        if (! $isDropShipment) {
            return '';
        }

        return "    <gs1ushc:dropShipment>true</gs1ushc:dropShipment>\n";
    }

    /**
     * GS1 US HC drop-shipment indicator on an EPCIS 2.0 JSON-LD document envelope.
     */
    public static function withDropShipmentDocumentField(string $json): string
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $document['gs1ushc:dropShipment'] = true;

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
    public static function assertDropShipmentEmitted(bool $isDropShipment, string $payload): void
    {
        if (! $isDropShipment) {
            return;
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
    ): string {
        return
            "    <sbdh:StandardBusinessDocumentHeader>\n".
            "      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>\n".
            "      <sbdh:Sender>\n".
            '        <sbdh:Identifier Authority="GLN">'.self::e($senderGln)."</sbdh:Identifier>\n".
            "      </sbdh:Sender>\n".
            "      <sbdh:Receiver>\n".
            '        <sbdh:Identifier Authority="GLN">'.self::e($receiverGln)."</sbdh:Identifier>\n".
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
