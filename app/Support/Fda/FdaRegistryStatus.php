<?php

namespace App\Support\Fda;

use App\Enums\PartnerType;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaWddLicense;
use Illuminate\Support\Carbon;

final class FdaRegistryStatus
{
    public const ESTABLISHMENT_REGISTERED = 'registered';

    public const ESTABLISHMENT_EXPIRED = 'expired';

    public const ESTABLISHMENT_EXCLUDED = 'excluded';

    public const LICENSE_ACTIVE = 'active';

    public const LICENSE_EXPIRED = 'expired';

    public const LICENSE_DELISTED = 'delisted';

    public const PRODUCT_RX = 'rx';

    public const PRODUCT_OTC = 'otc';

    public const IMPORT_SUCCESS = 'success';

    public const IMPORT_PARTIAL = 'partial';

    public const IMPORT_FAILED = 'failed';

    public static function establishment(FdaEstablishment $establishment): string
    {
        if ($establishment->exclusion_flag) {
            return self::ESTABLISHMENT_EXCLUDED;
        }

        $expiration = $establishment->expiration_date;
        if ($expiration instanceof Carbon && $expiration->lt(now()->startOfDay())) {
            return self::ESTABLISHMENT_EXPIRED;
        }

        return self::ESTABLISHMENT_REGISTERED;
    }

    public static function license(FdaWddLicense $license): string
    {
        if (! $license->is_active) {
            return self::LICENSE_DELISTED;
        }

        $expiration = $license->expiration_date;
        if ($expiration instanceof Carbon && $expiration->lt(now()->startOfDay())) {
            return self::LICENSE_EXPIRED;
        }

        return self::LICENSE_ACTIVE;
    }

    public static function productKind(FdaProduct $product): ?string
    {
        return match ($product->product_type) {
            FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION => self::PRODUCT_RX,
            FdaProduct::PRODUCT_TYPE_HUMAN_OTC => self::PRODUCT_OTC,
            default => null,
        };
    }

    public static function deaScheduleLabel(?string $schedule): ?string
    {
        if ($schedule === null || trim($schedule) === '') {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $schedule) ?? '');

        return match ($normalized) {
            '2', 'II', 'C2', 'CII' => 'CII',
            '3', 'III', 'C3', 'CIII' => 'CIII',
            '4', 'IV', 'C4', 'CIV' => 'CIV',
            '5', 'V', 'C5', 'CV' => 'CV',
            default => null,
        };
    }

    public static function importRun(FdaImportRun $run): string
    {
        if (! $run->isComplete()) {
            return self::IMPORT_FAILED;
        }

        if (((int) $run->rows_skipped + (int) $run->rows_sent_to_review) > 0) {
            return self::IMPORT_PARTIAL;
        }

        return self::IMPORT_SUCCESS;
    }

    public static function organizationAuthorization(FdaOrganization $organization): string
    {
        $type = $organization->partner_type;

        if ($type === PartnerType::Wholesaler || $type === PartnerType::Logistics3pl) {
            return self::wholesalerAuthorization($organization);
        }

        return self::manufacturerAuthorization($organization);
    }

    private static function manufacturerAuthorization(FdaOrganization $organization): string
    {
        $hasEstablishment = $organization->establishments()
            ->where('is_active', true)
            ->where('exclusion_flag', false)
            ->where(function ($query): void {
                $query->whereNull('expiration_date')
                    ->orWhereDate('expiration_date', '>=', now()->toDateString());
            })
            ->exists();

        $hasProduct = $organization->products()->where('is_active', true)->exists();

        if ($hasEstablishment || $hasProduct) {
            return 'Authorized via an active registered establishment and/or listed products. A WDD license is not required.';
        }

        return 'Not authorized: no active registered establishment or listed product.';
    }

    private static function wholesalerAuthorization(FdaOrganization $organization): string
    {
        $hasLicense = FdaWddLicense::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expiration_date')
                    ->orWhereDate('expiration_date', '>=', now()->toDateString());
            })
            ->whereHas('facility', function ($query) use ($organization): void {
                $query->where('fda_organization_id', $organization->id)
                    ->where('is_active', true);
            })
            ->exists();

        if ($hasLicense) {
            return 'Authorized via an active facility license.';
        }

        return 'Not authorized: no active, unexpired license on an active facility.';
    }
}
