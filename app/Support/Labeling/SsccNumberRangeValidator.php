<?php

declare(strict_types=1);

namespace App\Support\Labeling;

use App\Enums\SsccNumberRangeScope;
use App\Enums\SsccNumberRangeStatus;
use App\Models\SsccNumberRange;
use App\Models\TradingPartner;
use App\Services\Labeling\ResolveSsccNumberRange;
use App\Services\Labeling\SsccBuilder;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Validation helpers for creating / updating SSCC number ranges.
 */
final class SsccNumberRangeValidator
{
    public static function assertName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new InvalidArgumentException(
                'Name may only contain letters, numbers, underscores, and hyphens (API-safe).',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAndValidate(array $data, ?SsccNumberRange $existing = null): array
    {
        return DB::transaction(function () use ($data, $existing): array {
            return self::normalizeAndValidateLocked($data, $existing);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeAndValidateLocked(array $data, ?SsccNumberRange $existing = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        self::assertName($name);

        $threshold = max(1, min(100, (int) ($data['threshold_percentage'] ?? 80)));

        if ($existing === null) {
            return self::normalizeCreate($data, $name, $threshold);
        }

        return self::normalizeUpdate($data, $existing, $name, $threshold);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeCreate(array $data, string $name, int $threshold): array
    {
        $scope = $data['scope'] instanceof SsccNumberRangeScope
            ? $data['scope']
            : SsccNumberRangeScope::from((string) ($data['scope'] ?? 'tenant'));

        $siteId = isset($data['site_id']) && $data['site_id'] !== '' && $data['site_id'] !== null
            ? (int) $data['site_id']
            : null;
        $partnerId = isset($data['trading_partner_id']) && $data['trading_partner_id'] !== '' && $data['trading_partner_id'] !== null
            ? (int) $data['trading_partner_id']
            : null;

        $siteId = $scope === SsccNumberRangeScope::Site ? $siteId : null;
        $partnerId = $scope === SsccNumberRangeScope::Partner ? $partnerId : null;

        self::assertScopeOwners($scope, $siteId, $partnerId);

        $orgPrefix = (string) (TenantSettings::forTenant(tenant())->companyPrefix() ?? '');
        $companyPrefix = preg_replace('/\D+/', '', (string) ($data['company_prefix'] ?? $orgPrefix)) ?? '';

        if ($companyPrefix === '' || $orgPrefix === '' || $companyPrefix !== $orgPrefix) {
            throw new InvalidArgumentException(
                'Company prefix must match the organization GS1 Company Prefix in Organization Settings.',
            );
        }

        $extensionDigit = (string) ((int) ($data['extension_digit'] ?? 0));
        if (strlen($extensionDigit) !== 1 || $extensionDigit < '0' || $extensionDigit > '9') {
            throw new InvalidArgumentException('Extension digit must be a single digit 0–9.');
        }

        $incrementBy = max(1, min(100, (int) ($data['increment_by'] ?? 1)));
        $rangeSize = (int) ($data['range_size'] ?? 0);

        if ($rangeSize < 1) {
            throw new InvalidArgumentException('Number range size must be at least 1.');
        }

        $maxRangeSize = (int) config('sscc.max_range_size', 1_000_000);
        if ($rangeSize > $maxRangeSize) {
            throw new InvalidArgumentException("Number range size cannot exceed {$maxRangeSize}.");
        }

        $start = (int) ($data['start_number'] ?? 0);
        $current = (int) ($data['current_number'] ?? $start);

        if ($start < 0 || $current < 0) {
            throw new InvalidArgumentException('Start and current numbers must be zero or greater.');
        }

        if ($current < $start) {
            throw new InvalidArgumentException('Current number must be greater than or equal to the start number.');
        }

        if ((($current - $start) % $incrementBy) !== 0) {
            throw new InvalidArgumentException(
                'Current number must align with Start number using Increment by (same arithmetic sequence).',
            );
        }

        $lastIssuable = $start + (($rangeSize - 1) * $incrementBy);
        $maxNextPointer = $lastIssuable + $incrementBy;
        if ($current > $maxNextPointer) {
            throw new InvalidArgumentException('Current number is beyond the configured range size.');
        }

        $maxSerial = app(SsccBuilder::class)->maxSerialReferenceForPrefix($companyPrefix);
        if ($lastIssuable > $maxSerial) {
            throw new InvalidArgumentException(
                "Range end serial {$lastIssuable} exceeds the maximum {$maxSerial} for company prefix {$companyPrefix}.",
            );
        }

        self::assertNoOverlap($companyPrefix, $extensionDigit, $start, $lastIssuable, null);

        $index = self::nextIndex($companyPrefix, $extensionDigit, $scope, $siteId, $partnerId);

        $tmp = new SsccNumberRange([
            'company_prefix' => $companyPrefix,
            'extension_digit' => $extensionDigit,
            'start_number' => $start,
            'current_number' => $current,
            'increment_by' => $incrementBy,
            'range_size' => $rangeSize,
            'status' => SsccNumberRangeStatus::Active,
        ]);
        ResolveSsccNumberRange::advanceCurrentPastExistingLabels($tmp);
        $tmp->syncRemainingAndStatus();

        return [
            'name' => $name,
            'scope' => $scope,
            'site_id' => $siteId,
            'trading_partner_id' => $partnerId,
            'company_prefix' => $companyPrefix,
            'extension_digit' => $extensionDigit,
            'gs1_api_key' => filled($data['gs1_api_key'] ?? null) ? (string) $data['gs1_api_key'] : null,
            'index' => $index,
            'increment_by' => $incrementBy,
            'range_size' => $rangeSize,
            'start_number' => $start,
            'current_number' => (int) $tmp->current_number,
            'threshold_percentage' => $threshold,
            'status' => $tmp->status,
            'remaining' => $tmp->remaining,
            'threshold_notified_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeUpdate(
        array $data,
        SsccNumberRange $existing,
        string $name,
        int $threshold,
    ): array {
        /** @var SsccNumberRange $existing */
        $existing = SsccNumberRange::query()
            ->whereKey($existing->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $scope = $existing->scope;
        $siteId = $existing->site_id !== null ? (int) $existing->site_id : null;
        $partnerId = $existing->trading_partner_id !== null ? (int) $existing->trading_partner_id : null;

        $incrementBy = (int) $existing->increment_by;
        $previousStatus = $existing->status;

        $status = $data['status'] instanceof SsccNumberRangeStatus
            ? $data['status']
            : SsccNumberRangeStatus::from((string) ($data['status'] ?? $existing->status->value));

        if ($status === SsccNumberRangeStatus::Depleted) {
            $status = $existing->issuableCount() > 0
                ? SsccNumberRangeStatus::Active
                : SsccNumberRangeStatus::Depleted;
        }

        $becomingActive = $previousStatus !== SsccNumberRangeStatus::Active
            && $status === SsccNumberRangeStatus::Active;

        // Only re-validate the owner (site/partner eligibility) when a range is transitioning
        // into Active. An already-Active range whose owner has since become ineligible (an
        // "orphan") must still be editable (threshold, key, etc.) without forcing Inactive first.
        self::assertScopeOwners(
            $scope,
            $siteId,
            $partnerId,
            requireEligibleOwner: $becomingActive,
        );

        $clearGs1Key = (bool) ($data['clear_gs1_api_key'] ?? false);
        if (array_key_exists('gs1_api_key', $data) && filled($data['gs1_api_key'])) {
            $gs1Key = (string) $data['gs1_api_key'];
        } elseif ($clearGs1Key) {
            $gs1Key = null;
        } else {
            $gs1Key = $existing->gs1_api_key;
        }

        $existing->forceFill([
            'increment_by' => $incrementBy,
            'range_size' => $existing->range_size,
            'start_number' => $existing->start_number,
            'current_number' => $existing->current_number,
            'status' => $status,
        ]);

        if ($becomingActive) {
            ResolveSsccNumberRange::advanceCurrentPastExistingLabels($existing);
        }

        $existing->syncRemainingAndStatus();

        if ($becomingActive) {
            self::assertNoOverlap(
                companyPrefix: (string) $existing->company_prefix,
                extensionDigit: (string) $existing->extension_digit,
                start: (int) $existing->start_number,
                lastIssuable: $existing->lastIssuableNumber(),
                ignoreId: (int) $existing->getKey(),
            );
        }

        $thresholdChanged = $threshold !== (int) $existing->threshold_percentage;

        return [
            'name' => $name,
            'scope' => $existing->scope,
            'site_id' => $existing->site_id,
            'trading_partner_id' => $existing->trading_partner_id,
            'company_prefix' => $existing->company_prefix,
            'extension_digit' => $existing->extension_digit,
            'gs1_api_key' => $gs1Key,
            'increment_by' => $incrementBy,
            'threshold_percentage' => $threshold,
            'status' => $existing->status,
            'remaining' => $existing->remaining,
            'current_number' => $existing->current_number,
            'threshold_notified_at' => $thresholdChanged ? null : $existing->threshold_notified_at,
        ];
    }

    private static function assertScopeOwners(
        SsccNumberRangeScope $scope,
        ?int $siteId,
        ?int $partnerId,
        bool $requireEligibleOwner = true,
    ): void {
        if ($scope === SsccNumberRangeScope::Site) {
            if ($siteId === null || $siteId <= 0) {
                if ($requireEligibleOwner) {
                    throw new InvalidArgumentException('Select a site for a site-scoped number range.');
                }

                return;
            }

            if ($requireEligibleOwner
                && ! EligibleReceiveSites::forOrganization()->whereKey($siteId)->exists()
            ) {
                throw new InvalidArgumentException(
                    'Site-scoped ranges must use an active organization facility with a GLN.',
                );
            }

            return;
        }

        if ($scope === SsccNumberRangeScope::Partner) {
            if ($partnerId === null || $partnerId <= 0) {
                if ($requireEligibleOwner) {
                    throw new InvalidArgumentException('Select a trading partner for a partner-scoped number range.');
                }

                return;
            }

            if ($requireEligibleOwner
                && ! TradingPartner::query()->whereKey($partnerId)->where('is_active', true)->exists()
            ) {
                throw new InvalidArgumentException(
                    'Partner-scoped ranges must use an active trading partner.',
                );
            }
        }
    }

    private static function nextIndex(
        string $companyPrefix,
        string $extensionDigit,
        SsccNumberRangeScope $scope,
        ?int $siteId,
        ?int $partnerId,
    ): int {
        $query = SsccNumberRange::query()
            ->where('company_prefix', $companyPrefix)
            ->where('extension_digit', $extensionDigit)
            ->where('scope', $scope)
            ->orderBy('id')
            ->lockForUpdate();

        if ($scope === SsccNumberRangeScope::Site) {
            $query->where('site_id', $siteId);
        } elseif ($scope === SsccNumberRangeScope::Partner) {
            $query->where('trading_partner_id', $partnerId);
        } else {
            $query->whereNull('site_id')->whereNull('trading_partner_id');
        }

        return ((int) $query->max('index')) + 1;
    }

    private static function assertNoOverlap(
        string $companyPrefix,
        string $extensionDigit,
        int $start,
        int $lastIssuable,
        ?int $ignoreId,
    ): void {
        $others = SsccNumberRange::query()
            ->where('company_prefix', $companyPrefix)
            ->where('extension_digit', $extensionDigit)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'name', 'start_number', 'range_size', 'increment_by', 'current_number', 'status']);

        foreach ($others as $other) {
            $otherLast = $other->reservedLastNumber();
            if ($otherLast < (int) $other->start_number) {
                continue;
            }

            if (SsccNumberRange::bandIntersects(
                $start,
                $lastIssuable,
                (int) $other->start_number,
                $otherLast,
            )) {
                $statusLabel = $other->status instanceof SsccNumberRangeStatus
                    ? $other->status->label()
                    : (string) $other->status;

                throw new InvalidArgumentException(
                    "Serial band overlaps {$statusLabel} range \"{$other->name}\" (#{$other->id}).",
                );
            }
        }
    }
}
