<?php

namespace App\Services\Epcis\Outbound;

use Carbon\CarbonInterface;

final readonly class As2SendResult
{
    /**
     * @param  array<string, mixed>  $responseHeaders
     */
    public function __construct(
        public string $messageId,
        public string $mdnStatus,
        public ?CarbonInterface $mdnReceivedAt,
        public ?string $mdnBody,
        public int $httpStatus,
        public array $responseHeaders,
        public ?string $contentType,
        public bool $smimeApplied = false,
        public bool $certificatesConfigured = false,
    ) {}

    /**
     * Whether the outbound document may be marked transmission_status=sent.
     * Sync/async MDN acceptance, or no MDN required, counts as sent; explicit MDN failure does not.
     */
    public function marksDocumentSent(): bool
    {
        return $this->mdnStatus !== 'failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function mdnPayload(): array
    {
        return [
            'message_id' => $this->messageId,
            'http_status' => $this->httpStatus,
            'content_type' => $this->contentType,
            'headers' => $this->responseHeaders,
            'body' => $this->mdnBody,
            'smime_applied' => $this->smimeApplied,
            'certificates_configured' => $this->certificatesConfigured,
        ];
    }
}
