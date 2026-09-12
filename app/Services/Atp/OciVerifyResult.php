<?php

declare(strict_types=1);

namespace App\Services\Atp;

use Carbon\CarbonImmutable;

final class OciVerifyResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?string $issuer = null,
        public readonly ?string $subjectGln = null,
        public readonly ?CarbonImmutable $expiresAt = null,
    ) {}

    public static function missing(?string $reason = null): self
    {
        return new self(false, 'missing', $reason ?? 'ATP credential header missing');
    }

    public static function error(string $reason): self
    {
        return new self(false, 'error', $reason);
    }

    public static function fromWalletResponse(array $body): self
    {
        $valid = (bool) ($body['valid'] ?? false);
        $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null;
        $issuer = isset($body['issuer']) && is_string($body['issuer']) ? $body['issuer'] : null;
        $subject = isset($body['subject_gln']) && is_string($body['subject_gln'])
            ? preg_replace('/\D+/', '', $body['subject_gln'])
            : null;
        if (is_string($subject) && strlen($subject) !== 13) {
            $subject = null;
        }

        $expiresAt = null;
        $rawExp = $body['expires_at'] ?? null;
        if (is_string($rawExp) && $rawExp !== '') {
            try {
                $expiresAt = CarbonImmutable::parse($rawExp);
            } catch (\Throwable) {
                $expiresAt = null;
            }
        }

        if (! $valid) {
            $status = str_contains(strtolower((string) $reason), 'expir') ? 'expired' : 'invalid';

            return new self(false, $status, $reason, $issuer, $subject, $expiresAt);
        }

        if ($expiresAt !== null && $expiresAt->isPast()) {
            return new self(false, 'expired', $reason ?? 'Credential expired', $issuer, $subject, $expiresAt);
        }

        return new self(true, 'verified', $reason, $issuer, $subject, $expiresAt);
    }

    public function isAcceptable(): bool
    {
        return $this->valid && $this->status === 'verified';
    }
}
