<?php

namespace App\Actions\Exceptions;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Exceptions\ExceptionCase;
use App\Services\Exceptions\ExceptionService;
use Database\Seeders\ExceptionTypeSeeder;
use Throwable;

/**
 * Audit case when inbound EPCIS is rejected for a foreign/missing Receiver GLN.
 * The file is not persisted — this is the investigation ledger only.
 */
final class RecordInboundReceiverRejected
{
    public const EXCEPTION_CODE = 'INBOUND_RECEIVER_REJECTED';

    public function __construct(
        private readonly ExceptionService $exceptions,
    ) {}

    public function handle(string $detail): ?ExceptionCase
    {
        try {
            $type = $this->exceptions->resolveType(self::EXCEPTION_CODE)
                ?? ExceptionTypeSeeder::ensure(self::EXCEPTION_CODE);

            if ($type === null) {
                return null;
            }

            $fingerprint = mb_substr($detail, 0, 240);

            $existing = ExceptionCase::query()
                ->where('exception_type_id', $type->getKey())
                ->where('description', $detail)
                ->whereNotIn('status', [
                    ExceptionStatus::Resolved->value,
                    ExceptionStatus::Closed->value,
                    ExceptionStatus::Cancelled->value,
                ])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return $this->exceptions->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => null,
                'title' => 'Inbound EPCIS rejected — receiver GLN',
                'description' => $detail !== '' ? $detail : $fingerprint,
                'severity' => ExceptionSeverity::High->value,
                'status' => ExceptionStatus::New->value,
            ], [], null, notify: false);
        } catch (Throwable) {
            return null;
        }
    }
}
