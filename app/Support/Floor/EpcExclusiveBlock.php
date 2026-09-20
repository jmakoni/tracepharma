<?php

namespace App\Support\Floor;

/**
 * Why an EPC cannot be scanned on the current work session.
 */
final class EpcExclusiveBlock
{
    public function __construct(
        public readonly FloorSessionType $sessionType,
        public readonly int $sessionId,
        public readonly string $effect,
        public readonly string $message,
    ) {}

    /**
     * @return array{ok: false, message: string, effect: string, blocking_session_type: string, blocking_session_id: int}
     */
    public function toScanResult(): array
    {
        return [
            'ok' => false,
            'message' => $this->message,
            'effect' => $this->effect,
            'blocking_session_type' => $this->sessionType->value,
            'blocking_session_id' => $this->sessionId,
        ];
    }

    public function messageWithOpenHint(?string $url): string
    {
        if ($url === null || $url === '') {
            return $this->message;
        }

        return $this->message.' Open the blocking session to continue.';
    }

    /**
     * @param  array{message?: string, effect?: string, blocking_session_type?: string, blocking_session_id?: int}  $result
     */
    public static function fromScanResult(array $result): ?self
    {
        if (! isset($result['blocking_session_type'], $result['blocking_session_id'])) {
            return null;
        }

        return new self(
            FloorSessionType::from((string) $result['blocking_session_type']),
            (int) $result['blocking_session_id'],
            (string) ($result['effect'] ?? ''),
            (string) ($result['message'] ?? ''),
        );
    }

    public function dispositionRefusal(string $verb): string
    {
        return match ($this->effect) {
            'on_open_ship' => "Cannot {$verb} — this unit is already confirmed on an open ship order.",
            'double_transfer' => "Cannot {$verb} — this unit is already confirmed on an open or in-transit transfer.",
            'double_receive', 'on_open_receive' => "Cannot {$verb} — this unit is already confirmed on an open receive session.",
            'on_open_pack' => "Cannot {$verb} — this unit is reserved on an open pack session.",
            default => "Cannot {$verb} — this unit is reserved on another open work session.",
        };
    }
}
