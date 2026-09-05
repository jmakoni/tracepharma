<?php

namespace App\Support\Epcis;

use App\Support\TenantSettings;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Log;

/**
 * Detect SOAP envelopes around EPCIS and unwrap the inner EPCISDocument by default.
 */
final class EpcisSoapDocumentNormalizer
{
    public const SOAP_11_NS = 'http://schemas.xmlsoap.org/soap/envelope/';

    public const SOAP_12_NS = 'http://www.w3.org/2003/05/soap-envelope';

    public const STRICT_REJECT_MESSAGE = 'SOAP-wrapped EPCIS payloads are not accepted for this organization. Send a raw EPCISDocument, or turn off Require pure EPCISDocument in Organization Settings.';

    /**
     * @return array{content: string, unwrapped: bool}
     */
    public function normalize(string $content, ?bool $requirePure = null): array
    {
        $trimmed = ltrim($content);

        if ($trimmed === '' || ($trimmed[0] !== '<' && ! str_starts_with($trimmed, "\xEF\xBB\xBF<"))) {
            return ['content' => $content, 'unwrapped' => false];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            if (@$document->loadXML($content) === false) {
                return ['content' => $content, 'unwrapped' => false];
            }

            $root = $document->documentElement;
            if (! $root instanceof DOMElement || ! $this->isSoapEnvelope($root)) {
                return ['content' => $content, 'unwrapped' => false];
            }

            $requirePure ??= TenantSettings::forTenant(tenant())->requirePureEpcisDocument();

            if ($requirePure) {
                throw new \InvalidArgumentException(self::STRICT_REJECT_MESSAGE);
            }

            $inner = $this->extractEpcisDocument($root);
            if ($inner === null) {
                throw new \InvalidArgumentException(
                    'SOAP envelope does not contain a single EPCISDocument in the Body. Send raw EPCIS XML or a SOAP Body with one EPCISDocument.',
                );
            }

            $unwrapped = $this->exportElement($inner);

            Log::info('Unwrapped SOAP envelope around inbound EPCISDocument.', [
                'tenant_id' => tenant()?->getTenantKey(),
            ]);

            return ['content' => $unwrapped, 'unwrapped' => true];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Normalize a file in place when SOAP unwrap changes the bytes.
     *
     * @return array{path: string, unwrapped: bool, temporary: bool}
     */
    public function normalizeFile(string $absolutePath, ?bool $requirePure = null): array
    {
        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            throw new \InvalidArgumentException("EPCIS file is missing or unreadable: {$absolutePath}");
        }

        $result = $this->normalize($raw, $requirePure);

        if (! $result['unwrapped']) {
            return ['path' => $absolutePath, 'unwrapped' => false, 'temporary' => false];
        }

        $temp = tempnam(sys_get_temp_dir(), 'epcis-unwrap-');
        if ($temp === false) {
            throw new \RuntimeException('Unable to create temporary file for unwrapped EPCIS payload.');
        }

        $target = $temp.'.xml';
        if (! @rename($temp, $target)) {
            $target = $temp;
        }

        if (@file_put_contents($target, $result['content']) === false) {
            @unlink($target);
            throw new \RuntimeException('Unable to write unwrapped EPCIS payload.');
        }

        return ['path' => $target, 'unwrapped' => true, 'temporary' => true];
    }

    public function isSoapEnvelope(DOMElement $root): bool
    {
        if (strcasecmp($root->localName, 'Envelope') !== 0) {
            return false;
        }

        $ns = (string) $root->namespaceURI;

        return $ns === self::SOAP_11_NS || $ns === self::SOAP_12_NS;
    }

    private function extractEpcisDocument(DOMElement $envelope): ?DOMElement
    {
        $body = $this->firstChildByLocalName($envelope, 'Body');
        if ($body === null) {
            return null;
        }

        $matches = [];
        foreach ($body->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($this->isEpcisDocumentElement($child)) {
                $matches[] = $child;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function isEpcisDocumentElement(DOMElement $element): bool
    {
        return strcasecmp($element->localName, 'EPCISDocument') === 0;
    }

    private function firstChildByLocalName(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strcasecmp($child->localName, $localName) === 0) {
                return $child;
            }
        }

        return null;
    }

    private function exportElement(DOMElement $element): string
    {
        $export = new DOMDocument('1.0', 'UTF-8');
        $export->formatOutput = false;
        $imported = $export->importNode($element, true);
        $export->appendChild($imported);

        $xml = $export->saveXML();
        if ($xml === false || trim($xml) === '') {
            throw new \RuntimeException('Unable to serialize unwrapped EPCISDocument.');
        }

        return $xml;
    }
}
