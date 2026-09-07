<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectionGoLiveChecklist extends Model
{
    public const TYPE_INBOUND = 'inbound';

    public const TYPE_OUTBOUND = 'outbound';

    protected $fillable = [
        'connection_type',
        'connection_id',
        'steps',
        'signed_off_by',
        'signed_off_at',
        'break_glass_reason',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'signed_off_at' => 'datetime',
        ];
    }

    public static function forConnection(string $type, int $connectionId): self
    {
        return static::query()->firstOrCreate([
            'connection_type' => $type,
            'connection_id' => $connectionId,
        ]);
    }

    public function isSignedOff(): bool
    {
        return $this->signed_off_at !== null;
    }

    public function signOff(User $user): void
    {
        $this->forceFill([
            'signed_off_by' => $user->getKey(),
            'signed_off_at' => now(),
        ])->save();
    }

    public function recordBreakGlass(string $reason): void
    {
        $this->forceFill(['break_glass_reason' => $reason])->save();
    }

    /**
     * Manual completion recorded for a step (e.g. evidence reviewed offline).
     *
     * @return array{at: string|null, by: int|null, note: string|null}|null
     */
    public function manualStep(string $step): ?array
    {
        $entry = $this->steps[$step] ?? null;

        return is_array($entry) ? $entry : null;
    }

    public function markStep(string $step, User $user, ?string $note = null): void
    {
        $steps = $this->steps ?? [];
        $steps[$step] = [
            'at' => now()->toIso8601String(),
            'by' => $user->getKey(),
            'note' => $note,
        ];

        $this->forceFill(['steps' => $steps])->save();
    }
}
