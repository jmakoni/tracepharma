<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataExportStatus;
use App\Enums\DataExportType;
use App\Support\TenantAppUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DataExport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'type',
        'requested_by_user_id',
        'filters',
        'notify_email',
    ];

    protected function casts(): array
    {
        return [
            'type' => DataExportType::class,
            'status' => DataExportStatus::class,
            'filters' => 'array',
            'row_count' => 'integer',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DataExport $export): void {
            if (blank($export->id)) {
                $export->id = (string) Str::uuid();
            }

            if ($export->status === null) {
                $export->status = DataExportStatus::Pending;
            }
        });
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => DataExportStatus::Processing,
            'started_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markCompleted(int $rowCount, string $disk, string $path): void
    {
        $retentionDays = max(1, (int) config('tracepharma.exports.retention_days', 7));

        $this->forceFill([
            'status' => DataExportStatus::Completed,
            'row_count' => $rowCount,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'completed_at' => now(),
            'expires_at' => now()->addDays($retentionDays),
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->purgeStorage();

        $this->forceFill([
            'status' => DataExportStatus::Failed,
            'storage_disk' => null,
            'storage_path' => null,
            'error_message' => Str::limit($message, 2000),
            'completed_at' => now(),
        ])->save();
    }

    public function purgeStorage(): void
    {
        $disk = (string) ($this->storage_disk ?? '');
        $path = (string) ($this->storage_path ?? '');

        if ($disk === '' || $path === '') {
            return;
        }

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function temporaryDownloadUrl(?int $minutes = null, ?string $tenantId = null): ?string
    {
        if ($this->status !== DataExportStatus::Completed) {
            return null;
        }

        if ($this->isExpired()) {
            return null;
        }

        $relative = $this->storage_path;
        $disk = (string) ($this->storage_disk ?? '');

        if (! filled($relative) || $disk === '') {
            return null;
        }

        if (config("filesystems.disks.{$disk}.driver") === 's3') {
            $minutes ??= (int) config('tracepharma.exports.url_ttl_minutes', 60);

            return Storage::disk($disk)->temporaryUrl(
                (string) $relative,
                now()->addMinutes($minutes),
            );
        }

        $minutes ??= (int) config('tracepharma.exports.url_ttl_minutes', 60);

        return TenantAppUrl::temporarySignedRoute(
            'tenant.data-export.download',
            now()->addMinutes($minutes),
            ['export' => $this->getKey()],
            tenantId: $tenantId ?? (tenancy()->initialized ? (string) tenant('id') : null),
        );
    }

    public function storageObjectKey(): string
    {
        $tenantId = (string) (tenant('id') ?? 'unknown');

        return 'exports/'.$tenantId.'/'.$this->getKey().'.pdf';
    }
}
