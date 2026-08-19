<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Support\Gs1LocationNormalizer;
use DOMDocument;
use DOMXPath;

class SbdhHeaderExtractor
{
    /**
     * @return array{
     *     sender_gln: ?string,
     *     receiver_gln: ?string,
     *     sender_identifier: ?string,
     *     receiver_identifier: ?string
     * }
     */
    public function extract(?string $content): array
    {
        $empty = [
            'sender_gln' => null,
            'receiver_gln' => null,
            'sender_identifier' => null,
            'receiver_identifier' => null,
        ];

        if ($content === null || trim($content) === '') {
            return $empty;
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;

        if (! @$document->loadXML($this->prepareParseableXml($content))) {
            return $empty;
        }

        $xpath = new DOMXPath($document);

        $senderIdentifier = $this->partyIdentifier($xpath, 'Sender');
        $receiverIdentifier = $this->partyIdentifier($xpath, 'Receiver');

        return [
            'sender_gln' => Gs1LocationNormalizer::normalize($senderIdentifier),
            'receiver_gln' => Gs1LocationNormalizer::normalize($receiverIdentifier),
            'sender_identifier' => $senderIdentifier,
            'receiver_identifier' => $receiverIdentifier,
        ];
    }

    private function partyIdentifier(DOMXPath $xpath, string $party): ?string
    {
        $node = $xpath->query('//*[local-name()="'.$party.'"]/*[local-name()="Identifier"]')?->item(0);

        if ($node === null) {
            return null;
        }

        $value = trim((string) $node->textContent);

        return $value !== '' ? $value : null;
    }

    private function prepareParseableXml(string $content): string
    {
        $trimmed = trim($content);

        if (@(new DOMDocument)->loadXML($trimmed)) {
            return $trimmed;
        }

        $withoutDeclaration = preg_replace('/<\?xml[^?]*\?\>\s*/i', '', $trimmed) ?? $trimmed;

        return '<Payload>'.$withoutDeclaration.'</Payload>';
    }
}
