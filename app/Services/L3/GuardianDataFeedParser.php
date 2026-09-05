<?php

declare(strict_types=1);

namespace App\Services\L3;

use XMLReader;

/**
 * Stream-parse a Guardian (Systech) lot-close `DataFeed` XML into a plain array.
 *
 * Walks the document with {@see XMLReader} node-by-node — never loads the whole
 * file into memory (samples run ~25MB) — tracking a small stack of open
 * `Container` frames to resolve the Pallet -> Case -> Bottle parent chain.
 */
final class GuardianDataFeedParser
{
    /**
     * @return array{
     *     message_id: ?string,
     *     process_flow_action: ?string,
     *     envelope_timezone_offset: ?string,
     *     site_id_gln: ?string,
     *     site_name: ?string,
     *     line_name: ?string,
     *     lot_number: ?string,
     *     product_name: ?string,
     *     ndc: ?string,
     *     unit_gtin14: ?string,
     *     case_gtin14: ?string,
     *     expire_date: ?string,
     *     mfg_date: ?string,
     *     lot_processed_at: ?string,
     *     timezone_offset: ?string,
     *     lot_info_saved_at: ?string,
     *     lot_control_data: array<string, string>,
     *     containers: list<array{
     *         type: ?string,
     *         epc_uri: ?string,
     *         parent_epc_uri: ?string,
     *         fields: array<string, string>,
     *         event_time: ?string,
     *         timezone_offset: ?string
     *     }>
     * }
     */
    public function parse(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            throw new \InvalidArgumentException("Guardian DataFeed XML is not readable: {$absolutePath}");
        }

        $reader = new XMLReader;
        if (! $reader->open($absolutePath)) {
            throw new \RuntimeException("Unable to open Guardian DataFeed XML: {$absolutePath}");
        }

        $result = [
            'message_id' => null,
            'process_flow_action' => null,
            'envelope_timezone_offset' => null,
            'site_id_gln' => null,
            'site_name' => null,
            'line_name' => null,
            'lot_number' => null,
            'product_name' => null,
            'ndc' => null,
            'unit_gtin14' => null,
            'case_gtin14' => null,
            'expire_date' => null,
            'mfg_date' => null,
            'lot_processed_at' => null,
            'timezone_offset' => null,
            'lot_info_saved_at' => null,
            'lot_control_data' => [],
            'containers' => [],
        ];

        /** @var list<string> $path Ancestor element names, root..parent (current excluded). */
        $path = [];

        /**
         * @var list<array{
         *     type: ?string,
         *     fields: array<string, string>,
         *     event_time: ?string,
         *     timezone_offset: ?string,
         *     parent_epc_uri: ?string
         * }> $containerStack
         */
        $containerStack = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $name = $reader->localName;
                    $parent = $path[count($path) - 1] ?? null;
                    $isEmpty = $reader->isEmptyElement;

                    $this->handleElement($reader, $name, $parent, $containerStack, $result);

                    if (! $isEmpty) {
                        $path[] = $name;
                    }

                    continue;
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT) {
                    if ($reader->localName === 'Container') {
                        $frame = array_pop($containerStack);
                        if ($frame !== null) {
                            $result['containers'][] = [
                                'type' => $frame['type'],
                                'epc_uri' => $frame['fields']['URI'] ?? null,
                                'parent_epc_uri' => $frame['parent_epc_uri'],
                                'fields' => $frame['fields'],
                                'event_time' => $frame['event_time'],
                                'timezone_offset' => $frame['timezone_offset'],
                            ];
                        }
                    }

                    array_pop($path);
                }
            }
        } finally {
            $reader->close();
        }

        return $result;
    }

    /**
     * Lightweight `<MessageID>` peek from the first bytes of the file, for the
     * webhook receive path (never parses the whole 25MB body inline).
     */
    public function peekMessageId(string $absolutePath, int $maxBytes = 65536): ?string
    {
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $chunk = fread($handle, $maxBytes);
        } finally {
            fclose($handle);
        }

        if (! is_string($chunk) || $chunk === '') {
            return null;
        }

        if (preg_match('/<MessageID>\s*([^<\s][^<]*)\s*<\/MessageID>/', $chunk, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @param  list<array{type: ?string, fields: array<string, string>, event_time: ?string, parent_epc_uri: ?string}>  $containerStack
     * @param  array<string, mixed>  $result
     */
    private function handleElement(
        XMLReader $reader,
        string $name,
        ?string $parent,
        array &$containerStack,
        array &$result,
    ): void {
        if ($name === 'Container') {
            $parentUri = $containerStack !== []
                ? ($containerStack[array_key_last($containerStack)]['fields']['URI'] ?? null)
                : null;

            $containerStack[] = [
                'type' => null,
                'fields' => [],
                'event_time' => null,
                'timezone_offset' => null,
                'parent_epc_uri' => $parentUri,
            ];

            return;
        }

        if ($containerStack !== [] && $parent === 'Container') {
            $top = array_key_last($containerStack);

            if ($name === 'Type') {
                $containerStack[$top]['type'] = trim((string) $reader->readString());

                return;
            }

            if ($name === 'EventTimeStampUTC') {
                $containerStack[$top]['event_time'] = trim((string) $reader->readString());

                return;
            }

            if ($name === 'ContainerId') {
                $idName = (string) $reader->getAttribute('Name');
                $value = trim((string) $reader->readString());
                if ($idName !== '') {
                    $containerStack[$top]['fields'][$idName] = $value;
                }

                return;
            }

            // Container-scoped TimeZoneOffset pairs with EventTimeStampUTC (which,
            // despite the name, is a local timestamp in Guardian feeds) — needed to
            // convert each container's own event time to true UTC when authoring.
            if ($name === 'TimeZoneOffset') {
                $containerStack[$top]['timezone_offset'] = trim((string) $reader->readString());

                return;
            }

            // Container-scoped Quantity is not surfaced today (counts are derived
            // from type / hierarchy instead).
            if ($name === 'Quantity') {
                $reader->readString();

                return;
            }
        }

        if ($name === 'MessageID' && $parent === 'Envelope') {
            $result['message_id'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'ProcessFlowAction' && $parent === 'Envelope') {
            $result['process_flow_action'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'TimeZoneOffset' && $parent === 'Envelope') {
            $result['envelope_timezone_offset'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'SiteId' && $parent === 'Location') {
            $result['site_id_gln'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'SiteName' && $parent === 'Location') {
            $result['site_name'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'LineName' && $parent === 'Location') {
            $result['line_name'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'LotNumber' && $parent === 'LotInfo') {
            $result['lot_number'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'LotProcessedTime' && $parent === 'LotInfo') {
            $result['lot_processed_at'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'TimeZoneOffset' && $parent === 'LotInfo') {
            $result['timezone_offset'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'ExpireDate' && $parent === 'LotInfo') {
            $result['expire_date'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'ProductName' && $parent === 'LotInfo') {
            $result['product_name'] = trim((string) $reader->readString());

            return;
        }

        if ($name === 'ProductCode' && $parent === 'LotInfo') {
            $attrName = (string) $reader->getAttribute('Name');
            $value = trim((string) $reader->readString());
            if (strcasecmp($attrName, 'NDC') === 0) {
                $result['ndc'] = $value;
            }

            return;
        }

        if ($name === 'Data' && $parent === 'LotControlData') {
            $attrName = (string) $reader->getAttribute('Name');
            $value = trim((string) $reader->readString());

            if ($attrName === '') {
                return;
            }

            $result['lot_control_data'][$attrName] = $value;

            if ($attrName === 'UnitGTIN' && $this->looksLikeGtin($value)) {
                $result['unit_gtin14'] = $value;
            }

            if ($attrName === 'CaseGTIN' && $this->looksLikeGtin($value)) {
                $result['case_gtin14'] = $value;
            }

            if ($attrName === 'MfgDate' && $result['mfg_date'] === null) {
                $result['mfg_date'] = $value;
            }

            if ($attrName === '__SptLotInfo.LotInfoSaved__') {
                $result['lot_info_saved_at'] = $value;
            }
        }
    }

    private function looksLikeGtin(string $value): bool
    {
        return ctype_digit($value) && strlen($value) === 14;
    }
}
