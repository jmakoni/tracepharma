<?php

namespace App\Services\Integrations;

final class InboundPayloadResolver
{
    /**
     * @return array{content: string, originalName: ?string, contentType: ?string}
     */
    public function resolve(string $rawBody, ?string $contentType = null, ?string $filename = null): array
    {
        $trimmed = ltrim($rawBody);

        if ($this->isSoapEnvelope($trimmed)) {
            throw new \InvalidArgumentException(
                'SOAP-wrapped EPCIS payloads are not supported. Send raw EPCIS XML or JSON-LD.',
            );
        }

        $resolvedName = $this->normalizeFilename($filename, $contentType, $rawBody);

        return [
            'content' => $rawBody,
            'originalName' => $resolvedName,
            'contentType' => $contentType,
        ];
    }

    private function normalizeFilename(?string $filename, ?string $contentType, string $rawBody): ?string
    {
        if (filled($filename)) {
            return $filename;
        }

        $mime = strtolower(trim(explode(';', (string) $contentType)[0]));
        $trimmed = ltrim($rawBody);
        $looksJson = $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');

        if (in_array($mime, ['application/ld+json', 'application/json'], true) || $looksJson) {
            return 'inbound-'.now()->format('YmdHis').'.json';
        }

        if (in_array($mime, ['application/xml', 'text/xml'], true) || ($trimmed !== '' && $trimmed[0] === '<')) {
            return 'inbound-'.now()->format('YmdHis').'.xml';
        }

        return $filename;
    }

    private function isSoapEnvelope(string $content): bool
    {
        return preg_match('/^<\s*(?:soap|SOAP-ENV):/i', $content) === 1
            || str_contains(strtolower($content), ':envelope');
    }
}
