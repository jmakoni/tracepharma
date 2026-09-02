<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerificationRequestOutcome;
use App\Enums\VerificationRequestReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationRequestResponse extends Model
{
    protected $fillable = [
        'verification_request_case_id',
        'outcome',
        'reason_code',
        'comments',
        'responder_email',
        'responder_ip',
        'attachment_path',
        'terms_accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => VerificationRequestOutcome::class,
            'reason_code' => VerificationRequestReason::class,
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function verificationRequestCase(): BelongsTo
    {
        return $this->belongsTo(VerificationRequestCase::class);
    }
}
