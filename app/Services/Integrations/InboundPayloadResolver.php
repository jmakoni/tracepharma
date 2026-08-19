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
                'SOAP-wrapped EPCIS payloads are not supported. Send raw EPCIS XML.',
            );
        }

        return [
            'content' => $rawBody,
            'originalName' => $filename,
            'contentType' => $contentType,
        ];
    }

    private function isSoapEnvelope(string $content): bool
    {
        return preg_match('/^<\s*(?:soap|SOAP-ENV):/i', $content) === 1
            || str_contains(strtolower($content), ':envelope');
    }
}
