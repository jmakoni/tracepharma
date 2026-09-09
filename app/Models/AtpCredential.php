<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evidence capture for OCI-style ATP verifiable credentials presented on VRS
 * endpoints (ATP-Authorization / X-ATP-Credential headers).
 *
 * When tenant ATP OCI mode is warn/require, verification_status reflects the
 * wallet verify outcome. Mode off stores skipped (payload parse only).
 */
class AtpCredential extends Model
{
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_MISSING = 'missing';

    public const STATUS_ERROR = 'error';

    /** @var list<string> */
    protected $fillable = [
        'endpoint',
        'issuer',
        'subject_gln',
        'credential_expires_at',
        'header_sha256',
        'verification_status',
        'verification_reason',
        'raw_credential',
    ];

    protected function casts(): array
    {
        return [
            'credential_expires_at' => 'datetime',
            'raw_credential' => 'encrypted',
        ];
    }
}
