<?php

namespace App\Support\Epcis;

use App\Support\Gs1\Ndc;
use App\Support\Gs1\Sgln;
use XMLReader;

/**
 * Stream-parse EPCIS 1.2 XML into a plain array document DTO.
 */
final class EpcisXmlReader
{
    private const EVENT_LOCAL_NAMES = [
        'ObjectEvent',
        'AggregationEvent',
        'TransactionEvent',
        'TransformationEvent',
        'AssociationEvent',
    ];

    /**
     * @return array{
     *     schema_version: string,
     *     creation_date: string|null,
     *     document_uuid: string,
     *     sender_gln: string|null,
     *     receiver_gln: string|null,
     *     dscsa_affirm: bool,
     *     legal_notice: string|null,
     *     product_classes: list<array{
     *         idpat: string,
     *         ndc11: string|null,
     *         ndc_raw: string|null,
     *         name: string|null,
     *         dosage_form: string|null,
     *         strength: string|null,
     *         manufacturer: string|null,
     *         net_content: string|null,
     *         placeholder_fields?: list<string>,
     *         attributes_json: array<string, string>
     *     }>,
     *     locations: list<array{
     *         gln_uri: string,
     *         gln: string|null,
     *         name: string|null,
     *         street_address: string|null,
     *         city: string|null,
     *         state: string|null,
     *         postal_code: string|null,
     *         country_code: string|null,
     *         attributes_json: array<string, string>
     *     }>,
     *     other_vocabulary: list<array{
     *         vocabulary_type: string,
     *         element_id: string,
     *         attributes_json: array<string, string>
     *     }>,
     *     header_json: array<string, mixed>|null,
     *     dropped_epc_uris: list<string>,
     *     events: list<array<string, mixed>>
     * }
     */
    public function parse(string $absolutePath): array
    {
        $events = [];

        $header = $this->parseHeaderAndStream($absolutePath, function (array $event) use (&$events): void {
            $events[] = $event;
        });

        unset($header['events_streamed']);
        $header['events'] = $events;

        return $header;
    }

    /**
     * Parse EPCIS header/master-data only (stops at EPCISBody).
     *
     * @return array{
     *     schema_version: string,
     *     creation_date: string|null,
     *     document_uuid: string,
     *     sender_gln: string|null,
     *     receiver_gln: string|null,
     *     dscsa_affirm: bool,
     *     legal_notice: string|null,
     *     product_classes: list<array{
     *         idpat: string,
     *         ndc11: string|null,
     *         ndc_raw: string|null,
     *         name: string|null,
     *         dosage_form: string|null,
     *         strength: string|null,
     *         manufacturer: string|null,
     *         net_content: string|null,
     *         placeholder_fields?: list<string>,
     *         attributes_json: array<string, string>
     *     }>,
     *     locations: list<array{
     *         gln_uri: string,
     *         gln: string|null,
     *         name: string|null,
     *         street_address: string|null,
     *         city: string|null,
     *         state: string|null,
     *         postal_code: string|null,
     *         country_code: string|null,
     *         attributes_json: array<string, string>
     *     }>,
     *     other_vocabulary: list<array{
     *         vocabulary_type: string,
     *         element_id: string,
     *         attributes_json: array<string, string>
     *     }>,
     *     header_json: array<string, mixed>|null,
     *     dropped_epc_uris: list<string>,
     *     events_streamed: int
     * }
     */
    public function parseHeader(string $absolutePath): array
    {
        return $this->parseHeaderAndStream($absolutePath, static function (): void {}, headerOnly: true);
    }

    /**
     * Stream-parse EPCIS XML, invoking $onEvent for each event as it is parsed.
     *
     * @param  callable(array<string, mixed>): void  $onEvent
     * @return array{
     *     schema_version: string,
     *     creation_date: string|null,
     *     document_uuid: string,
     *     sender_gln: string|null,
     *     receiver_gln: string|null,
     *     dscsa_affirm: bool,
     *     legal_notice: string|null,
     *     product_classes: list<array{
     *         idpat: string,
     *         ndc11: string|null,
     *         ndc_raw: string|null,
     *         name: string|null,
     *         dosage_form: string|null,
     *         strength: string|null,
     *         manufacturer: string|null,
     *         net_content: string|null,
     *         placeholder_fields?: list<string>,
     *         attributes_json: array<string, string>
     *     }>,
     *     locations: list<array{
     *         gln_uri: string,
     *         gln: string|null,
     *         name: string|null,
     *         street_address: string|null,
     *         city: string|null,
     *         state: string|null,
     *         postal_code: string|null,
     *         country_code: string|null,
     *         attributes_json: array<string, string>
     *     }>,
     *     other_vocabulary: list<array{
     *         vocabulary_type: string,
     *         element_id: string,
     *         attributes_json: array<string, string>
     *     }>,
     *     header_json: array<string, mixed>|null,
     *     dropped_epc_uris: list<string>,
     *     events_streamed: int
     * }
     */
    public function parseHeaderAndStream(string $absolutePath, callable $onEvent, bool $headerOnly = false): array
    {
        if (! is_readable($absolutePath)) {
            throw new \InvalidArgumentException("EPCIS XML is not readable: {$absolutePath}");
        }

        $reader = new XMLReader;
        if (! $reader->open($absolutePath)) {
            throw new \RuntimeException("Unable to open EPCIS XML: {$absolutePath}");
        }

        $schemaVersion = '1.2';
        $creationDate = null;
        $documentUuid = null;
        $senderGln = null;
        $receiverGln = null;
        $dscsaAffirm = false;
        $legalNotice = null;
        $productClasses = [];
        $locations = [];
        $otherVocabulary = [];
        $headerJson = null;
        $eventsStreamed = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $localName = $reader->localName;

                if ($localName === 'EPCISDocument') {
                    $schemaVersion = $reader->getAttribute('schemaVersion') ?: $schemaVersion;
                    $creationDate = $reader->getAttribute('creationDate') ?: $creationDate;

                    continue;
                }

                if ($localName === 'InstanceIdentifier') {
                    $documentUuid = trim((string) $reader->readString()) ?: $documentUuid;

                    continue;
                }

                if ($localName === 'StandardBusinessDocumentHeader') {
                    $header = $this->toSimpleXml($reader);
                    if ($header !== null) {
                        $senderGln = $this->firstLocalText($header, 'Sender', 'Identifier') ?? $senderGln;
                        $receiverGln = $this->firstLocalText($header, 'Receiver', 'Identifier') ?? $receiverGln;
                        $documentUuid = $this->firstLocalText($header, 'DocumentIdentification', 'InstanceIdentifier')
                            ?? $documentUuid;
                        $creationDate = $this->firstLocalText($header, 'DocumentIdentification', 'CreationDateAndTime')
                            ?? $creationDate;
                        $headerJson = $this->extractSbdhHeaderJson($header);
                    }

                    continue;
                }

                if ($localName === 'Vocabulary') {
                    $type = (string) ($reader->getAttribute('type') ?? '');
                    if (str_contains($type, 'EPCClass')) {
                        $vocab = $this->toSimpleXml($reader);
                        if ($vocab !== null) {
                            foreach ($this->parseEpcClassVocabulary($vocab) as $productClass) {
                                $productClasses[] = $productClass;
                            }
                        }
                    } elseif (str_contains($type, 'Location')) {
                        $vocab = $this->toSimpleXml($reader);
                        if ($vocab !== null) {
                            foreach ($this->parseLocationVocabulary($vocab) as $location) {
                                $locations[] = $location;
                            }
                        }
                    } else {
                        $vocab = $this->toSimpleXml($reader);
                        if ($vocab !== null) {
                            foreach ($this->parseOtherVocabulary($vocab, $type) as $element) {
                                $otherVocabulary[] = $element;
                            }
                        }
                    }

                    continue;
                }

                if ($localName === 'dscsaTransactionStatement') {
                    $stmt = $this->toSimpleXml($reader);
                    if ($stmt !== null) {
                        $affirm = $this->firstByLocalName($stmt, 'affirmTransactionStatement');
                        $dscsaAffirm = $affirm !== null && filter_var(trim((string) $affirm), FILTER_VALIDATE_BOOLEAN);
                        $notice = $this->firstByLocalName($stmt, 'legalNotice');
                        $legalNotice = $notice !== null ? trim((string) $notice) : $legalNotice;
                    }

                    continue;
                }

                if ($headerOnly && ($localName === 'EPCISBody' || in_array($localName, self::EVENT_LOCAL_NAMES, true))) {
                    break;
                }

                if (in_array($localName, self::EVENT_LOCAL_NAMES, true)) {
                    $eventXml = $this->toSimpleXml($reader);
                    if ($eventXml !== null) {
                        $onEvent($this->parseEvent($eventXml, $localName));
                        $eventsStreamed++;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return [
            'schema_version' => $schemaVersion,
            'creation_date' => $creationDate,
            'document_uuid' => $documentUuid ?: (string) str()->uuid(),
            'sender_gln' => $senderGln !== null && $senderGln !== '' ? preg_replace('/\D+/', '', $senderGln) : null,
            'receiver_gln' => $receiverGln !== null && $receiverGln !== '' ? preg_replace('/\D+/', '', $receiverGln) : null,
            'dscsa_affirm' => $dscsaAffirm,
            'legal_notice' => $legalNotice !== '' ? $legalNotice : null,
            'product_classes' => $productClasses,
            'locations' => $locations,
            'other_vocabulary' => $otherVocabulary,
            'header_json' => $headerJson,
            'dropped_epc_uris' => [],
            'events_streamed' => $eventsStreamed,
        ];
    }

    /**
     * Residual SBDH fields not already extracted as top-level header columns.
     *
     * @return array<string, mixed>|null
     */
    private function extractSbdhHeaderJson(\SimpleXMLElement $header): ?array
    {
        $extra = [];

        $headerVersion = $this->firstLocalText($header, 'HeaderVersion');
        if ($headerVersion !== null) {
            $extra['HeaderVersion'] = $headerVersion;
        }

        $documentIdentification = [];
        foreach (['Standard', 'TypeVersion', 'Type', 'MultipleType'] as $field) {
            $value = $this->firstLocalText($header, 'DocumentIdentification', $field);
            if ($value !== null) {
                $documentIdentification[$field] = $value;
            }
        }
        if ($documentIdentification !== []) {
            $extra['DocumentIdentification'] = $documentIdentification;
        }

        $senderAuthority = $this->identifierAuthority($header, 'Sender');
        if ($senderAuthority !== null) {
            $extra['Sender'] = ['IdentifierAuthority' => $senderAuthority];
        }

        $receiverAuthority = $this->identifierAuthority($header, 'Receiver');
        if ($receiverAuthority !== null) {
            $extra['Receiver'] = ['IdentifierAuthority' => $receiverAuthority];
        }

        $businessScope = [];
        foreach ($this->childrenByLocalName($header, 'BusinessScope') as $scope) {
            foreach ($this->childrenByLocalName($scope, 'Scope') as $scopeNode) {
                $type = $this->firstLocalText($scopeNode, 'Type');
                $instanceId = $this->firstLocalText($scopeNode, 'InstanceIdentifier');
                $row = array_filter([
                    'Type' => $type,
                    'InstanceIdentifier' => $instanceId,
                ], static fn ($v) => $v !== null);
                if ($row !== []) {
                    $businessScope[] = $row;
                }
            }
        }
        if ($businessScope !== []) {
            $extra['BusinessScope'] = $businessScope;
        }

        return $extra !== [] ? $extra : null;
    }

    private function identifierAuthority(\SimpleXMLElement $header, string $partyLocalName): ?string
    {
        foreach ($this->childrenByLocalName($header, $partyLocalName) as $party) {
            foreach ($this->childrenByLocalName($party, 'Identifier') as $identifier) {
                $attrs = $identifier->attributes();
                if ($attrs === null) {
                    continue;
                }
                $authority = trim((string) ($attrs['Authority'] ?? ''));
                if ($authority !== '') {
                    return $authority;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{
     *     vocabulary_type: string,
     *     element_id: string,
     *     attributes_json: array<string, string>
     * }>
     */
    private function parseOtherVocabulary(\SimpleXMLElement $vocabulary, string $vocabularyType): array
    {
        $elements = [];
        $type = trim($vocabularyType);

        foreach ($this->childrenByLocalName($vocabulary, 'VocabularyElementList') as $list) {
            foreach ($this->childrenByLocalName($list, 'VocabularyElement') as $element) {
                $elementId = trim((string) ($element['id'] ?? ''));
                if ($elementId === '') {
                    continue;
                }

                $attrs = [];
                foreach ($this->childrenByLocalName($element, 'attribute') as $attribute) {
                    $attrId = trim((string) ($attribute['id'] ?? ''));
                    if ($attrId === '') {
                        continue;
                    }
                    $attrs[$attrId] = trim((string) $attribute);
                }

                $elements[] = [
                    'vocabulary_type' => $type !== '' ? $type : 'unknown',
                    'element_id' => $elementId,
                    'attributes_json' => $attrs,
                ];
            }
        }

        return $elements;
    }

    /**
     * @return list<array{
     *     gln_uri: string,
     *     gln: string|null,
     *     name: string|null,
     *     street_address: string|null,
     *     city: string|null,
     *     state: string|null,
     *     postal_code: string|null,
     *     country_code: string|null,
     *     attributes_json: array<string, string>
     * }>
     */
    private function parseLocationVocabulary(\SimpleXMLElement $vocabulary): array
    {
        $locations = [];

        foreach ($this->childrenByLocalName($vocabulary, 'VocabularyElementList') as $list) {
            foreach ($this->childrenByLocalName($list, 'VocabularyElement') as $element) {
                $glnUri = trim((string) ($element['id'] ?? ''));
                if ($glnUri === '') {
                    continue;
                }

                $attrs = [];
                foreach ($this->childrenByLocalName($element, 'attribute') as $attribute) {
                    $attrId = trim((string) ($attribute['id'] ?? ''));
                    if ($attrId === '') {
                        continue;
                    }
                    $attrs[$attrId] = trim((string) $attribute);
                }

                $name = $attrs['urn:epcglobal:cbv:mda#name']
                    ?? $attrs['name']
                    ?? null;
                $street = $attrs['urn:epcglobal:cbv:mda#streetAddressOne']
                    ?? $attrs['streetAddressOne']
                    ?? null;
                $city = $attrs['urn:epcglobal:cbv:mda#city']
                    ?? $attrs['city']
                    ?? null;
                $state = $attrs['urn:epcglobal:cbv:mda#state']
                    ?? $attrs['state']
                    ?? null;
                $postalCode = $attrs['urn:epcglobal:cbv:mda#postalCode']
                    ?? $attrs['postalCode']
                    ?? null;
                $countryCode = $attrs['urn:epcglobal:cbv:mda#countryCode']
                    ?? $attrs['countryCode']
                    ?? null;

                $parsed = Sgln::fromUrn($glnUri);

                $locations[] = [
                    'gln_uri' => $glnUri,
                    'gln' => $parsed['gln'] ?? null,
                    'name' => filled($name) ? (string) $name : null,
                    'street_address' => filled($street) ? (string) $street : null,
                    'city' => filled($city) ? (string) $city : null,
                    'state' => filled($state) ? (string) $state : null,
                    'postal_code' => filled($postalCode) ? (string) $postalCode : null,
                    'country_code' => filled($countryCode) ? (string) $countryCode : null,
                    'attributes_json' => $attrs,
                ];
            }
        }

        return $locations;
    }

    /**
     * @return list<array{
     *     idpat: string,
     *     ndc11: string|null,
     *     ndc_raw: string|null,
     *     name: string|null,
     *     dosage_form: string|null,
     *     strength: string|null,
     *     manufacturer: string|null,
     *     net_content: string|null,
     *     placeholder_fields: list<string>,
     *     attributes_json: array<string, string>
     * }>
     */
    private function parseEpcClassVocabulary(\SimpleXMLElement $vocabulary): array
    {
        $classes = [];
        $placeholderAttrLocalNames = [
            'regulatedProductName',
            'dosageFormType',
            'strengthDescription',
            'netContentDescription',
        ];

        foreach ($this->childrenByLocalName($vocabulary, 'VocabularyElementList') as $list) {
            foreach ($this->childrenByLocalName($list, 'VocabularyElement') as $element) {
                $idpat = trim((string) ($element['id'] ?? ''));
                if ($idpat === '' || ! str_contains($idpat, 'idpat:sgtin:')) {
                    continue;
                }

                $attrs = [];
                foreach ($this->childrenByLocalName($element, 'attribute') as $attribute) {
                    $attrId = trim((string) ($attribute['id'] ?? ''));
                    if ($attrId === '') {
                        continue;
                    }
                    $attrs[$attrId] = trim((string) $attribute);
                }

                $ndc11 = null;
                $typeCode = $attrs['urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode']
                    ?? $attrs['additionalTradeItemIdentificationTypeCode']
                    ?? null;
                $identification = $attrs['urn:epcglobal:cbv:mda#additionalTradeItemIdentification']
                    ?? $attrs['additionalTradeItemIdentification']
                    ?? null;
                $ndcRaw = filled($identification) ? (string) $identification : null;

                if (filled($identification)) {
                    $typeCodeUpper = strtoupper((string) $typeCode);

                    if ($typeCodeUpper === 'FDA_NDC_11' || $typeCode === null) {
                        $digits = preg_replace('/\D+/', '', (string) $identification) ?? '';
                        if (strlen($digits) === 11) {
                            $ndc11 = $digits;
                        }
                    } elseif ($typeCodeUpper === 'US_FDA_NDC') {
                        // Dashed 4-4-2/5-3-2/5-4-1 or bare 10/11-digit NDC; normalize to canonical NDC-11.
                        $ndc11 = Ndc::toNdc11((string) $identification) ?? $ndc11;
                    }
                }

                // Also accept FDA_NDC_11 attribute id directly when present.
                foreach ($attrs as $attrId => $value) {
                    if (str_contains($attrId, 'FDA_NDC_11') || str_ends_with($attrId, '#FDA_NDC_11')) {
                        $digits = preg_replace('/\D+/', '', $value) ?? '';
                        if (strlen($digits) === 11) {
                            $ndc11 = $digits;
                        }
                    }
                }

                $name = $attrs['urn:epcglobal:cbv:mda#regulatedProductName']
                    ?? $attrs['regulatedProductName']
                    ?? $attrs['urn:epcglobal:cbv:mda#name']
                    ?? null;
                $dosageForm = $attrs['urn:epcglobal:cbv:mda#dosageFormType']
                    ?? $attrs['dosageFormType']
                    ?? null;
                $strength = $attrs['urn:epcglobal:cbv:mda#strengthDescription']
                    ?? $attrs['strengthDescription']
                    ?? null;
                $manufacturer = $attrs['urn:epcglobal:cbv:mda#manufacturerOfTradeItemPartyName']
                    ?? $attrs['manufacturerOfTradeItemPartyName']
                    ?? null;
                $netContent = $attrs['urn:epcglobal:cbv:mda#netContentDescription']
                    ?? $attrs['netContentDescription']
                    ?? null;

                $placeholderFields = [];
                foreach ($placeholderAttrLocalNames as $localName) {
                    $value = $attrs['urn:epcglobal:cbv:mda#'.$localName]
                        ?? $attrs[$localName]
                        ?? null;
                    if ($this->isPlaceholderMasterDataValue($value)) {
                        $placeholderFields[] = $localName;
                    }
                }

                $classes[] = [
                    'idpat' => $idpat,
                    'ndc11' => $ndc11,
                    'ndc_raw' => $ndcRaw,
                    'name' => filled($name) && ! $this->isPlaceholderMasterDataValue($name) ? (string) $name : null,
                    'dosage_form' => filled($dosageForm) && ! $this->isPlaceholderMasterDataValue($dosageForm)
                        ? (string) $dosageForm
                        : null,
                    'strength' => filled($strength) && ! $this->isPlaceholderMasterDataValue($strength)
                        ? (string) $strength
                        : null,
                    'manufacturer' => filled($manufacturer) && ! $this->isPlaceholderMasterDataValue($manufacturer)
                        ? (string) $manufacturer
                        : null,
                    'net_content' => filled($netContent) && ! $this->isPlaceholderMasterDataValue($netContent)
                        ? (string) $netContent
                        : null,
                    'placeholder_fields' => $placeholderFields,
                    'attributes_json' => $attrs,
                ];
            }
        }

        return $classes;
    }

    private function isPlaceholderMasterDataValue(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = strtoupper(trim($value));

        return in_array($normalized, ['N/A', 'NA', 'N.A.', 'NULL', 'NONE', 'UNKNOWN', '-'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    public static function collectUniqueEpcUris(array $events): array
    {
        $uris = [];

        foreach ($events as $event) {
            foreach ($event['epcs'] ?? [] as $epc) {
                $uri = trim((string) ($epc['uri'] ?? ''));
                if ($uri !== '') {
                    $uris[$uri] = true;
                }
            }
        }

        return array_keys($uris);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseEvent(\SimpleXMLElement $event, string $eventType): array
    {
        $epcs = [];

        foreach ($this->childrenByLocalName($event, 'epcList') as $epcList) {
            foreach ($this->childrenByLocalName($epcList, 'epc') as $epc) {
                $uri = trim((string) $epc);
                if ($uri !== '') {
                    $epcs[] = ['uri' => $uri, 'role' => 'epcList'];
                }
            }
        }

        foreach ($this->childrenByLocalName($event, 'parentID') as $parentId) {
            $uri = trim((string) $parentId);
            if ($uri !== '') {
                $epcs[] = ['uri' => $uri, 'role' => 'parentID'];
            }
        }

        foreach ($this->childrenByLocalName($event, 'childEPCs') as $childEpcs) {
            foreach ($this->childrenByLocalName($childEpcs, 'epc') as $epc) {
                $uri = trim((string) $epc);
                if ($uri !== '') {
                    $epcs[] = ['uri' => $uri, 'role' => 'childEPC'];
                }
            }
        }

        foreach ($this->childrenByLocalName($event, 'inputEPCList') as $inputList) {
            foreach ($this->childrenByLocalName($inputList, 'epc') as $epc) {
                $uri = trim((string) $epc);
                if ($uri !== '') {
                    $epcs[] = ['uri' => $uri, 'role' => 'inputEPC'];
                }
            }
        }

        foreach ($this->childrenByLocalName($event, 'outputEPCList') as $outputList) {
            foreach ($this->childrenByLocalName($outputList, 'epc') as $epc) {
                $uri = trim((string) $epc);
                if ($uri !== '') {
                    $epcs[] = ['uri' => $uri, 'role' => 'outputEPC'];
                }
            }
        }

        $quantities = [];
        foreach ($this->childrenByLocalName($event, 'quantityList') as $quantityList) {
            foreach ($this->parseQuantityElements($quantityList, 'quantityList') as $qty) {
                $quantities[] = $qty;
            }
        }
        foreach ($this->childrenByLocalName($event, 'childQuantityList') as $childQuantityList) {
            foreach ($this->parseQuantityElements($childQuantityList, 'childQuantityList') as $qty) {
                $quantities[] = $qty;
            }
        }
        foreach ($this->childrenByLocalName($event, 'inputQuantityList') as $inputQuantityList) {
            foreach ($this->parseQuantityElements($inputQuantityList, 'inputQuantityList') as $qty) {
                $quantities[] = $qty;
            }
        }
        foreach ($this->childrenByLocalName($event, 'outputQuantityList') as $outputQuantityList) {
            foreach ($this->parseQuantityElements($outputQuantityList, 'outputQuantityList') as $qty) {
                $quantities[] = $qty;
            }
        }

        $this->attachQuantitiesToEpcs($epcs, $quantities);
        $classQuantities = $this->unmatchedClassQuantities($epcs, $quantities);

        $readPointUri = null;
        foreach ($this->childrenByLocalName($event, 'readPoint') as $readPoint) {
            $readPointUri = $this->firstByLocalName($readPoint, 'id');
            $readPointUri = $readPointUri !== null ? trim((string) $readPointUri) : null;
        }

        $bizLocationUri = null;
        foreach ($this->childrenByLocalName($event, 'bizLocation') as $bizLocation) {
            $bizLocationUri = $this->firstByLocalName($bizLocation, 'id');
            $bizLocationUri = $bizLocationUri !== null ? trim((string) $bizLocationUri) : null;
        }

        $bizTransactions = [];
        foreach ($this->childrenByLocalName($event, 'bizTransactionList') as $list) {
            foreach ($this->childrenByLocalName($list, 'bizTransaction') as $bt) {
                $type = (string) ($bt['type'] ?? '');
                $value = trim((string) $bt);
                if ($value !== '') {
                    $bizTransactions[] = [
                        'type_uri' => $type !== '' ? $type : 'unknown',
                        'value' => $value,
                    ];
                }
            }
        }

        $partiesByKey = [];
        $ilmd = null;
        $extensionJson = [];

        // EPCIS 2.0-ish producers place source/destination at event root.
        $this->appendSourceDestParties($event, $partiesByKey);

        foreach ($this->childrenByLocalName($event, 'extension') as $extension) {
            $this->appendSourceDestParties($extension, $partiesByKey);

            foreach ($this->childrenByLocalName($extension, 'ilmd') as $ilmdNode) {
                $ilmd = $this->parseIlmdNode($ilmdNode);
            }

            $dscsaExtensions = DscsaShippingExtensionParser::parseXmlExtension($extension);
            if ($dscsaExtensions !== null && ! $dscsaExtensions->isEmpty()) {
                $extensionJson['dscsa'] = $dscsaExtensions->toArray();
            }

            foreach ($this->collectUnknownExtensionChildren($extension) as $localName => $value) {
                $extensionJson[$localName] = $value;
            }
        }

        $parties = array_values($partiesByKey);

        // Also accept ilmd directly under the event (some producers omit extension wrapper).
        if ($ilmd === null) {
            foreach ($this->childrenByLocalName($event, 'ilmd') as $ilmdNode) {
                $ilmd = $this->parseIlmdNode($ilmdNode);
            }
        }

        $eventTime = $this->firstByLocalName($event, 'eventTime');
        $recordTime = $this->firstByLocalName($event, 'recordTime');
        $offset = $this->firstByLocalName($event, 'eventTimeZoneOffset');
        $action = $this->firstByLocalName($event, 'action');
        $bizStep = $this->firstByLocalName($event, 'bizStep');
        $disposition = $this->firstByLocalName($event, 'disposition');
        $eventIdNode = $this->firstByLocalName($event, 'eventID');
        $eventId = $eventIdNode !== null ? trim((string) $eventIdNode) : null;
        if ($eventId === null || $eventId === '') {
            $eventId = $this->firstLocalText($event, 'baseExtension', 'eventID');
        }

        $transformationIdNode = $this->firstByLocalName($event, 'transformationID');
        $transformationId = $transformationIdNode !== null ? trim((string) $transformationIdNode) : null;

        return [
            'event_type' => $eventType,
            'event_id' => $eventId !== '' ? $eventId : null,
            'event_time' => $eventTime !== null ? trim((string) $eventTime) : null,
            'record_time' => $recordTime !== null ? trim((string) $recordTime) : null,
            'event_timezone_offset' => $offset !== null ? trim((string) $offset) : null,
            'action' => $action !== null ? trim((string) $action) : 'ADD',
            'biz_step' => $bizStep !== null ? trim((string) $bizStep) : null,
            'disposition' => $disposition !== null ? trim((string) $disposition) : null,
            'persistent_disposition' => $this->parsePersistentDisposition($event),
            'transformation_id' => $transformationId !== '' ? $transformationId : null,
            'read_point_uri' => $readPointUri !== '' ? $readPointUri : null,
            'biz_location_uri' => $bizLocationUri !== '' ? $bizLocationUri : null,
            'epcs' => $epcs,
            'quantities' => $quantities,
            'class_quantities' => $classQuantities,
            'biz_transactions' => $bizTransactions,
            'parties' => $parties,
            'ilmd' => $ilmd,
            'extension_json' => $extensionJson !== [] ? $extensionJson : null,
            'error_declaration' => $this->parseErrorDeclaration($event),
        ];
    }

    /**
     * @return array{
     *     lot_number: string|null,
     *     expiry_date: string|null,
     *     manufacturing_date: string|null,
     *     best_before_date: string|null,
     *     additional_id: string|null,
     *     extra_json: array<string, string>|null
     * }
     */
    private function parseIlmdNode(\SimpleXMLElement $ilmdNode): array
    {
        $known = [
            'lotNumber' => 'lot_number',
            'itemExpirationDate' => 'expiry_date',
            'manufacturingDate' => 'manufacturing_date',
            'bestBeforeDate' => 'best_before_date',
            'additionalId' => 'additional_id',
        ];

        $result = [
            'lot_number' => null,
            'expiry_date' => null,
            'manufacturing_date' => null,
            'best_before_date' => null,
            'additional_id' => null,
            'extra_json' => null,
        ];

        $extra = [];

        foreach ($this->allChildElements($ilmdNode) as $child) {
            $localName = $child->getName();
            $value = trim((string) $child);
            if ($value === '') {
                continue;
            }

            if (isset($known[$localName])) {
                $result[$known[$localName]] = $value;

                continue;
            }

            $extra[$localName] = $value;
        }

        $result['extra_json'] = $extra !== [] ? $extra : null;

        return $result;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private function allChildElements(\SimpleXMLElement $parent): array
    {
        $matches = [];

        foreach ($parent->children() as $child) {
            $matches[] = $child;
        }

        foreach ($parent->getNamespaces(true) as $prefix => $ns) {
            foreach ($parent->children($ns) as $child) {
                $matches[] = $child;
            }
            unset($prefix);
        }

        $unique = [];
        foreach ($matches as $match) {
            $unique[spl_object_id($match)] = $match;
        }

        return array_values($unique);
    }

    /**
     * @return list<array{epc_class: string|null, quantity: float|null, uom: string|null, role: string}>
     */
    private function parseQuantityElements(\SimpleXMLElement $list, string $role): array
    {
        $rows = [];

        foreach ($this->childrenByLocalName($list, 'quantityElement') as $element) {
            $epcClassNode = $this->firstByLocalName($element, 'epcClass');
            $quantityNode = $this->firstByLocalName($element, 'quantity');
            $uomNode = $this->firstByLocalName($element, 'uom');

            $epcClass = $epcClassNode !== null ? trim((string) $epcClassNode) : null;
            $quantityRaw = $quantityNode !== null ? trim((string) $quantityNode) : '';
            $uom = $uomNode !== null ? trim((string) $uomNode) : null;

            $rows[] = [
                'epc_class' => $epcClass !== '' ? $epcClass : null,
                'quantity' => $quantityRaw !== '' && is_numeric($quantityRaw) ? (float) $quantityRaw : null,
                'uom' => $uom !== '' ? $uom : null,
                'role' => $role,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $epcs
     * @param  list<array{epc_class: string|null, quantity: float|null, uom: string|null, role: string}>  $quantities
     */
    private function attachQuantitiesToEpcs(array &$epcs, array $quantities): void
    {
        foreach ($quantities as $qty) {
            $classUri = $qty['epc_class'] ?? null;
            if ($classUri === null || $classUri === '') {
                continue;
            }

            foreach ($epcs as $index => $epc) {
                $uri = (string) ($epc['uri'] ?? '');
                if ($uri === '') {
                    continue;
                }

                if ($uri === $classUri || $this->epcMatchesClass($uri, $classUri)) {
                    $epcs[$index]['quantity'] = $qty['quantity'];
                    $epcs[$index]['uom'] = $qty['uom'];
                }
            }
        }
    }

    private function epcMatchesClass(string $epcUri, string $epcClass): bool
    {
        // idpat:sgtin:prefix.item.* matches id:sgtin:prefix.item.serial
        if (str_contains($epcClass, 'idpat:') && str_ends_with($epcClass, '.*')) {
            $prefix = substr($epcClass, 0, -2);
            $prefix = str_replace('idpat:', 'id:', $prefix);

            return str_starts_with($epcUri, $prefix.'.');
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $epcs
     * @param  list<array{epc_class: string|null, quantity: float|null, uom: string|null, role: string}>  $quantities
     * @return list<array{role: string, epc_class: string, quantity: float|null, uom: string|null}>
     */
    private function unmatchedClassQuantities(array $epcs, array $quantities): array
    {
        $rows = [];

        foreach ($quantities as $qty) {
            $classUri = $qty['epc_class'] ?? null;
            if ($classUri === null || $classUri === '') {
                continue;
            }

            $matched = false;
            foreach ($epcs as $epc) {
                $uri = (string) ($epc['uri'] ?? '');
                if ($uri === '') {
                    continue;
                }

                if ($uri === $classUri || $this->epcMatchesClass($uri, $classUri)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $rows[] = [
                    'role' => (string) $qty['role'],
                    'epc_class' => $classUri,
                    'quantity' => $qty['quantity'],
                    'uom' => $qty['uom'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Unknown extension children (not sourceList / destinationList / ilmd).
     *
     * @return array<string, string|array<string, string>>
     */
    private function collectUnknownExtensionChildren(\SimpleXMLElement $extension): array
    {
        $skip = array_merge(
            ['sourceList', 'destinationList', 'ilmd'],
            DscsaShippingExtensionParser::dscsaExtensionSkipLocalNames(),
        );
        $out = [];

        foreach ($this->allChildElements($extension) as $child) {
            $localName = $child->getName();
            if (in_array($localName, $skip, true)) {
                continue;
            }

            $nested = [];
            foreach ($this->allChildElements($child) as $grandChild) {
                $text = trim((string) $grandChild);
                if ($text !== '') {
                    $nested[$grandChild->getName()] = $text;
                }
            }

            if ($nested !== []) {
                $out[$localName] = $nested;

                continue;
            }

            $text = trim((string) $child);
            if ($text !== '') {
                $out[$localName] = $text;
            }
        }

        return $out;
    }

    /**
     * @return array{set: list<string>, unset: list<string>}|string|null
     */
    private function parsePersistentDisposition(\SimpleXMLElement $event): array|string|null
    {
        $node = $this->firstByLocalName($event, 'persistentDisposition');
        if ($node === null) {
            return null;
        }

        $set = [];
        $unset = [];

        foreach ($this->childrenByLocalName($node, 'set') as $setNode) {
            $value = trim((string) $setNode);
            if ($value !== '') {
                $set[] = $value;
            }
        }

        foreach ($this->childrenByLocalName($node, 'unset') as $unsetNode) {
            $value = trim((string) $unsetNode);
            if ($value !== '') {
                $unset[] = $value;
            }
        }

        if ($set !== [] || $unset !== []) {
            return [
                'set' => $set,
                'unset' => $unset,
            ];
        }

        $text = trim((string) $node);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, array{party_role: string, gln_uri: string, source_dest_type: string, type_uri: string|null}>  $partiesByKey
     */
    private function appendSourceDestParties(\SimpleXMLElement $parent, array &$partiesByKey): void
    {
        foreach ($this->childrenByLocalName($parent, 'sourceList') as $sourceList) {
            foreach ($this->childrenByLocalName($sourceList, 'source') as $source) {
                $uri = trim((string) $source);
                if ($uri === '') {
                    continue;
                }
                $typeUri = trim((string) ($source['type'] ?? ''));
                $row = [
                    'party_role' => 'source',
                    'gln_uri' => $uri,
                    'source_dest_type' => $this->shortSourceDestType($typeUri),
                    'type_uri' => $typeUri !== '' ? $typeUri : null,
                ];
                $partiesByKey[$row['party_role'].'|'.$row['gln_uri'].'|'.$row['source_dest_type']] = $row;
            }
        }

        foreach ($this->childrenByLocalName($parent, 'destinationList') as $destinationList) {
            foreach ($this->childrenByLocalName($destinationList, 'destination') as $destination) {
                $uri = trim((string) $destination);
                if ($uri === '') {
                    continue;
                }
                $typeUri = trim((string) ($destination['type'] ?? ''));
                $row = [
                    'party_role' => 'destination',
                    'gln_uri' => $uri,
                    'source_dest_type' => $this->shortSourceDestType($typeUri),
                    'type_uri' => $typeUri !== '' ? $typeUri : null,
                ];
                $partiesByKey[$row['party_role'].'|'.$row['gln_uri'].'|'.$row['source_dest_type']] = $row;
            }
        }
    }

    /**
     * @return array{
     *     declaration_time: string|null,
     *     reason: string|null,
     *     corrective_event_ids: list<string>,
     *     xml: string|null
     * }|null
     */
    private function parseErrorDeclaration(\SimpleXMLElement $event): ?array
    {
        $node = $this->firstByLocalName($event, 'errorDeclaration');
        if ($node === null) {
            return null;
        }

        $declarationTime = $this->firstByLocalName($node, 'declarationTime');
        $reason = $this->firstByLocalName($node, 'reason');
        $correctiveIds = [];

        foreach ($this->childrenByLocalName($node, 'correctiveEventIDs') as $list) {
            foreach ($this->childrenByLocalName($list, 'correctiveEventID') as $idNode) {
                $id = trim((string) $idNode);
                if ($id !== '') {
                    $correctiveIds[] = $id;
                }
            }
        }

        $xml = $node->asXML();
        $xmlText = is_string($xml) ? $xml : null;

        return [
            'declaration_time' => $declarationTime !== null ? trim((string) $declarationTime) : null,
            'reason' => $reason !== null ? trim((string) $reason) : null,
            'corrective_event_ids' => $correctiveIds,
            'xml' => $xmlText !== '' ? $xmlText : null,
        ];
    }

    private function toSimpleXml(XMLReader $reader): ?\SimpleXMLElement
    {
        $outer = $reader->readOuterXml();
        if ($outer === '' || $outer === false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $previousEntityLoader = null;
        if (\function_exists('libxml_disable_entity_loader')) {
            $previousEntityLoader = @libxml_disable_entity_loader(true);
        }

        try {
            $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
            if (defined('LIBXML_COMPACT')) {
                $flags |= LIBXML_COMPACT;
            }

            $xml = simplexml_load_string($outer, \SimpleXMLElement::class, $flags);

            return $xml instanceof \SimpleXMLElement ? $xml : null;
        } finally {
            if (\function_exists('libxml_disable_entity_loader') && $previousEntityLoader !== null) {
                @libxml_disable_entity_loader((bool) $previousEntityLoader);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private function childrenByLocalName(\SimpleXMLElement $parent, string $localName): array
    {
        $matches = [];

        foreach ($parent->children() as $child) {
            if ($child->getName() === $localName) {
                $matches[] = $child;
            }
        }

        foreach ($parent->getNamespaces(true) as $prefix => $ns) {
            foreach ($parent->children($ns) as $child) {
                if ($child->getName() === $localName) {
                    $matches[] = $child;
                }
            }
            unset($prefix);
        }

        // Deduplicate by object hash (default + namespaced children can overlap).
        $unique = [];
        foreach ($matches as $match) {
            $unique[spl_object_id($match)] = $match;
        }

        return array_values($unique);
    }

    private function firstByLocalName(\SimpleXMLElement $parent, string $localName): ?\SimpleXMLElement
    {
        $matches = $this->childrenByLocalName($parent, $localName);

        return $matches[0] ?? null;
    }

    private function firstLocalText(\SimpleXMLElement $parent, string ...$path): ?string
    {
        $current = $parent;

        foreach ($path as $segment) {
            $next = $this->firstByLocalName($current, $segment);
            if ($next === null) {
                return null;
            }
            $current = $next;
        }

        $text = trim((string) $current);

        return $text !== '' ? $text : null;
    }

    private function shortSourceDestType(string $typeUri): string
    {
        $typeUri = trim($typeUri);
        if ($typeUri === '') {
            return 'unknown';
        }

        if (str_contains($typeUri, ':')) {
            $parts = explode(':', $typeUri);

            return (string) end($parts);
        }

        return $typeUri;
    }
}
