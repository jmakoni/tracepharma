<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Support\TenantAppUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DataExport */
final class DataExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $downloadUrl = null;

        if ($this->status === DataExportStatus::Completed && ! $this->isExpired()) {
            $downloadUrl = $this->temporaryDownloadUrl();
        }

        return [
            'export_id' => (string) $this->getKey(),
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'row_count' => $this->row_count,
            'download_url' => $downloadUrl,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'status_url' => TenantAppUrl::forPath('/api/v1/exports/'.$this->getKey()),
        ];
    }
}
