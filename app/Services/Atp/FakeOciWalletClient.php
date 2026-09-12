<?php

declare(strict_types=1);

namespace App\Services\Atp;

use Carbon\CarbonImmutable;

/**
 * Programmable OCI wallet double for unit/feature tests. No network I/O.
 */
class FakeOciWalletClient extends OciWalletClient
{
    /** @var 'valid'|'expired'|'invalid'|'error' */
    private string $verifyOutcome = 'valid';

    private string $presentVp = 'eyJhbGciOiJub25lIn0.eyJzdWIiOiIwMzAwMDAxMDAwMDAxIn0.sig';

    private ?string $verifyReason = null;

    public function __construct()
    {
        parent::__construct([
            'base_url' => 'https://oci-wallet.test',
            'api_key' => 'test-key',
            'verify_path' => '/api/v1/verify-vp',
            'present_path' => '/api/v1/generate-vp',
            'timeout' => 5,
        ]);
    }

    public function willVerifyAs(string $outcome, ?string $reason = null): self
    {
        $this->verifyOutcome = $outcome;
        $this->verifyReason = $reason;

        return $this;
    }

    public function willPresent(string $vp): self
    {
        $this->presentVp = $vp;

        return $this;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function verify(string $vp): OciVerifyResult
    {
        $vp = trim($vp);
        if ($vp === '') {
            return OciVerifyResult::missing();
        }

        return match ($this->verifyOutcome) {
            'valid' => new OciVerifyResult(
                true,
                'verified',
                $this->verifyReason,
                'did:example:issuer',
                '0300001000001',
                CarbonImmutable::now()->addDay(),
            ),
            'expired' => new OciVerifyResult(
                false,
                'expired',
                $this->verifyReason ?? 'Credential expired',
                'did:example:issuer',
                '0300001000001',
                CarbonImmutable::now()->subDay(),
            ),
            'invalid' => new OciVerifyResult(
                false,
                'invalid',
                $this->verifyReason ?? 'Signature invalid',
            ),
            default => OciVerifyResult::error($this->verifyReason ?? 'Wallet error'),
        };
    }

    public function present(?string $subjectGln = null): string
    {
        return $this->presentVp;
    }
}
