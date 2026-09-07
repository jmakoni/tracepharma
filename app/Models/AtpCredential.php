<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evidence capture for OCI-style ATP verifiable credentials presented on VRS
 * endpoints (ATP-Authorization / X-ATP-Credential headers). Stored as seen —
 * signature verification against an OCI wallet is a future integration.
 */
class AtpCredential extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'endpoint',
        'issuer',
        'subject_gln',
        'credential_expires_at',
        'header_sha256',
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
