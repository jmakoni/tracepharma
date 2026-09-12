<?php

namespace App\Services\Integrations;

use App\Support\Epcis\EpcisSoapDocumentNormalizer;

final class InboundPayloadResolver
{
    public function __construct(
        private readonly EpcisSoapDocumentNormalizer $soapNormalizer = new EpcisSoapDocumentNormalizer,
    ) {}

    /**
     * @return array{content: string, originalName: ?string, contentType: ?string}
     */
    public function resolve(string $rawBody, ?string $contentType = null, ?string $filename = null): array
    {
        $normalized = $this->soapNormalizer->normalize($rawBody);
        $content = $normalized['content'];

        $resolvedName = $this->normalizeFilename($filename, $contentType, $content);

        return [
            'content' => $content,
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
}
