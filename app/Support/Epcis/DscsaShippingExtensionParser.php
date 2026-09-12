<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use SimpleXMLElement;

final class DscsaShippingExtensionParser
{
    private const DIRECT_PURCHASE = 'directPurchase';

    private const RECEIVED_PREV = 'receivedDirectPurchaseFromPrevWhlsDist';

    private const DIRECT_STATEMENT = 'directPurchaseStatement';

    private const RECEIVED_STATEMENT = 'receivedDirectPurchaseFromPrevWhlsDistStatement';

    private const INDIRECT_EPCS = 'indirectPurchaseEPCs';

    private const PREV_INDIRECT_EPCS = 'prevReceivedinDirectPurchaseEPCs';

    /**
     * @param  array<string, mixed>  $eventData
     */
    public static function fromEventData(array $eventData): ?DscsaShippingExtensionData
    {
        $bizStep = strtolower((string) ($eventData['biz_step'] ?? ''));
        if ($bizStep === '' || ! str_contains($bizStep, 'shipping')) {
            return null;
        }

        $extensionJson = $eventData['extension_json'] ?? null;
        if (! is_array($extensionJson)) {
            return null;
        }

        $dscsa = $extensionJson['dscsa'] ?? null;
        if (! is_array($dscsa)) {
            return null;
        }

        $parsed = DscsaShippingExtensionData::fromArray($dscsa);

        return $parsed->isEmpty() ? null : $parsed;
    }

    public static function parseXmlExtension(SimpleXMLElement $extension): ?DscsaShippingExtensionData
    {
        $direct = self::parsePurchaseBlock($extension, self::DIRECT_PURCHASE, self::DIRECT_STATEMENT, self::INDIRECT_EPCS);
        $received = self::parsePurchaseBlock($extension, self::RECEIVED_PREV, self::RECEIVED_STATEMENT, self::PREV_INDIRECT_EPCS);

        if ($direct === null || $received === null) {
            foreach (self::childrenMatchingLocalName($extension, 'extension') as $nested) {
                $direct ??= self::parsePurchaseBlock($nested, self::DIRECT_PURCHASE, self::DIRECT_STATEMENT, self::INDIRECT_EPCS);
                $received ??= self::parsePurchaseBlock($nested, self::RECEIVED_PREV, self::RECEIVED_STATEMENT, self::PREV_INDIRECT_EPCS);
            }
        }

        if ($direct === null && $received === null) {
            return null;
        }

        return new DscsaShippingExtensionData(
            directPurchase: $direct,
            receivedPrevWholesaler: $received,
        );
    }

    /**
     * @param  array<string, mixed>  $extension
     */
    public static function parseJsonExtension(array $extension): ?DscsaShippingExtensionData
    {
        $direct = self::parsePurchaseArray($extension, self::DIRECT_PURCHASE, self::DIRECT_STATEMENT, self::INDIRECT_EPCS);
        $received = self::parsePurchaseArray($extension, self::RECEIVED_PREV, self::RECEIVED_STATEMENT, self::PREV_INDIRECT_EPCS);

        if ($direct === null && $received === null) {
            return null;
        }

        return new DscsaShippingExtensionData(
            directPurchase: $direct,
            receivedPrevWholesaler: $received,
        );
    }

    /**
     * @return list<string>
     */
    public static function dscsaExtensionSkipLocalNames(): array
    {
        return [
            self::DIRECT_PURCHASE,
            self::RECEIVED_PREV,
        ];
    }

    private static function parsePurchaseBlock(
        SimpleXMLElement $parent,
        string $blockName,
        string $statementName,
        string $indirectListName,
    ): ?DscsaPurchaseExtension {
        foreach (self::childrenMatchingLocalName($parent, $blockName) as $child) {
            return self::buildPurchaseExtension($child, $statementName, $indirectListName);
        }

        return null;
    }

    private static function buildPurchaseExtension(
        SimpleXMLElement $block,
        string $statementName,
        string $indirectListName,
    ): ?DscsaPurchaseExtension {
        $qualifier = self::xmlAttribute($block, 'qualifier');
        $statement = self::firstChildText($block, $statementName);

        if ($qualifier === null && $statement === null) {
            $value = trim((string) $block);
            if ($value === 'true' || $value === '1') {
                $qualifier = 'ENTIRELY_DIRECT';
            } elseif ($value === 'false' || $value === '0') {
                return null;
            }
        }

        $indirect = self::collectEpcUrisFromList($block, $indirectListName);

        if ($qualifier === null && $statement === null && $indirect === []) {
            return null;
        }

        return new DscsaPurchaseExtension(
            qualifier: $qualifier,
            statement: $statement,
            indirectEpcUris: $indirect,
        );
    }

    /**
     * @param  array<string, mixed>  $parent
     */
    private static function parsePurchaseArray(
        array $parent,
        string $blockName,
        string $statementName,
        string $indirectListName,
    ): ?DscsaPurchaseExtension {
        if (! array_key_exists($blockName, $parent)) {
            return null;
        }

        $block = $parent[$blockName];
        if (is_bool($block)) {
            return $block
                ? new DscsaPurchaseExtension(qualifier: 'ENTIRELY_DIRECT', statement: null)
                : null;
        }

        if (is_string($block)) {
            $trimmed = trim($block);
            if ($trimmed === 'true' || $trimmed === '1') {
                return new DscsaPurchaseExtension(qualifier: 'ENTIRELY_DIRECT', statement: null);
            }

            return null;
        }

        if (! is_array($block)) {
            return null;
        }

        $qualifier = filled($block['qualifier'] ?? $block['@qualifier'] ?? null)
            ? (string) ($block['qualifier'] ?? $block['@qualifier'])
            : null;
        $statement = filled($block[$statementName] ?? null) ? (string) $block[$statementName] : null;
        $indirect = self::collectEpcUrisFromArray($block[$indirectListName] ?? null);

        if ($qualifier === null && $statement === null && $indirect === []) {
            return null;
        }

        return new DscsaPurchaseExtension(
            qualifier: $qualifier,
            statement: $statement,
            indirectEpcUris: $indirect,
        );
    }

    /**
     * @return list<string>
     */
    private static function collectEpcUrisFromList(SimpleXMLElement $block, string $listName): array
    {
        foreach (self::childrenMatchingLocalName($block, $listName) as $child) {
            return self::collectEpcUrisFromXmlList($child);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function collectEpcUrisFromXmlList(SimpleXMLElement $list): array
    {
        $uris = [];

        foreach ($list->children() as $child) {
            $text = trim((string) $child);
            if ($text !== '') {
                $uris[] = $text;
            }
        }

        if ($uris === []) {
            $text = trim((string) $list);
            if ($text !== '') {
                $uris[] = $text;
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @return list<string>
     */
    private static function collectEpcUrisFromArray(mixed $value): array
    {
        if (! is_array($value)) {
            return is_string($value) && trim($value) !== '' ? [trim($value)] : [];
        }

        $uris = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $uris[] = trim($item);
            } elseif (is_array($item)) {
                $epc = $item['epc'] ?? $item['uri'] ?? $item['value'] ?? null;
                if (is_string($epc) && trim($epc) !== '') {
                    $uris[] = trim($epc);
                }
            }
        }

        return array_values(array_unique($uris));
    }

    private static function firstChildText(SimpleXMLElement $parent, string $localName): ?string
    {
        foreach (self::childrenMatchingLocalName($parent, $localName) as $child) {
            $text = trim((string) $child);

            return $text !== '' ? $text : null;
        }

        return null;
    }

    private static function xmlAttribute(SimpleXMLElement $element, string $name): ?string
    {
        $attributes = $element->attributes();
        if ($attributes === null) {
            return null;
        }

        foreach ($attributes as $key => $value) {
            if ((string) $key === $name) {
                $text = trim((string) $value);

                return $text !== '' ? $text : null;
            }
        }

        return null;
    }

    private static function localName(SimpleXMLElement $element): string
    {
        $name = $element->getName();
        if (str_contains($name, ':')) {
            return substr($name, strrpos($name, ':') + 1);
        }

        return $name;
    }

    /**
     * @return iterable<int, SimpleXMLElement>
     */
    private static function childrenMatchingLocalName(SimpleXMLElement $parent, string $localName): iterable
    {
        $seen = [];

        foreach ([null, 'http://epcis.gs1us.org/hc/ns'] as $namespace) {
            $children = $namespace === null ? $parent->children() : $parent->children($namespace);
            foreach ($children as $child) {
                if (self::localName($child) !== $localName) {
                    continue;
                }

                $key = spl_object_id($child);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                yield $child;
            }
        }
    }
}
