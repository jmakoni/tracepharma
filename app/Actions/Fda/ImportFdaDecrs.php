<?php

namespace App\Actions\Fda;

use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaEstablishmentOperation;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\DecrsAddressParser;
use App\Support\Fda\FdaDecrsDataset;
use App\Support\Fda\OrganizationMatcher;
use Illuminate\Support\Carbon;

final class ImportFdaDecrs
{
    public function __construct(
        private readonly FdaDecrsDataset $dataset,
        private readonly ResolveFdaOrganization $orgResolver,
    ) {}

    /**
     * @return array{
     *     read: int,
     *     inserted: int,
     *     updated: int,
     *     skipped: int,
     *     sent_to_review: int,
     *     import_run_id: int
     * }
     */
    public function handle(string $txtPath, ?string $sourcePath = null): array
    {
        $started = microtime(true);
        $run = FdaImportRun::query()->create([
            'source' => 'decrs',
            'source_path' => $sourcePath ?? $txtPath,
            'sha256' => $this->fileHash($sourcePath ?? $txtPath),
            'started_at' => now(),
        ]);

        $counts = [
            'read' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'sent_to_review' => 0,
        ];

        $index = FdaOrganization::query()
            ->get(['id', 'canonical_name', 'duns_number'])
            ->map(static fn (FdaOrganization $org): array => [
                'id' => (int) $org->id,
                'canonical_name' => (string) $org->canonical_name,
                'duns_number' => $org->duns_number,
            ])
            ->values()
            ->all();

        foreach ($this->dataset->eachRow($txtPath) as $row) {
            $counts['read']++;

            $fei = $this->nullable($row['FEI_NUMBER'] ?? null);

            if ($fei === null) {
                $counts['skipped']++;

                continue;
            }

            $registrantName = $this->nullable($row['REGISTRANT_NAME'] ?? null)
                ?? $this->nullable($row['FIRM_NAME'] ?? null);

            if ($registrantName === null) {
                $counts['skipped']++;

                continue;
            }

            // Org identity is REGISTRANT_DUNS only — never fall back to establishment DUNS.
            $duns = OrganizationMatcher::normalizeDuns(
                $this->nullable($row['REGISTRANT_DUNS'] ?? null)
            );

            $resolved = $this->orgResolver->handle(
                'decrs',
                $registrantName,
                $duns,
                $index,
                ['fei_number' => $fei, 'firm_name' => $row['FIRM_NAME'] ?? null]
            );

            if ($resolved['reviewed'] || $resolved['organization'] === null) {
                $counts['sent_to_review']++;

                continue;
            }

            $this->upsertEstablishment($row, $fei, $resolved['organization'], $counts);
        }

        $run->forceFill([
            'rows_read' => $counts['read'],
            'rows_inserted' => $counts['inserted'],
            'rows_updated' => $counts['updated'],
            'rows_skipped' => $counts['skipped'],
            'rows_sent_to_review' => $counts['sent_to_review'],
            'completed_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ])->save();

        $counts['import_run_id'] = (int) $run->getKey();

        return $counts;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array{read: int, inserted: int, updated: int, skipped: int, sent_to_review: int}  $counts
     */
    private function upsertEstablishment(array $row, string $fei, FdaOrganization $org, array &$counts): void
    {
        $parsed = DecrsAddressParser::parse((string) ($row['ADDRESS'] ?? ''));
        $fingerprint = AddressFingerprint::fromParsed($parsed);
        $expiration = $this->parseExpiration($row['EXPIRATION_DATE'] ?? null);
        $excluded = strtoupper((string) ($row['EXCLUSION_FLAG'] ?? 'N')) === 'Y';
        $registered = ! $excluded && ($expiration === null || $expiration->gte(now()->startOfDay()));

        $attributes = [
            'fda_organization_id' => $org->id,
            'firm_name' => $this->nullable($row['FIRM_NAME'] ?? null) ?? $org->original_name,
            'duns_number' => OrganizationMatcher::normalizeDuns($row['DUNS_NUMBER'] ?? null),
            'street_address' => $this->clip($parsed['street_address'], 255),
            'city' => $this->clip($parsed['city'], 100),
            'state_province' => $this->clip($parsed['state_province'], 64),
            'postal_code' => $this->clip($parsed['postal_code'], 20),
            'country_code' => $this->iso2($parsed['country_code']),
            'full_address' => $parsed['full_address'] !== '' ? $parsed['full_address'] : null,
            'address_fingerprint' => $fingerprint,
            'expiration_date' => $expiration?->toDateString(),
            'exclusion_flag' => $excluded,
            'is_currently_registered' => $registered,
            'establishment_contact_name' => $this->nullable($row['ESTABLISHMENT_CONTACT_NAME'] ?? null),
            'establishment_contact_email' => $this->nullable($row['ESTABLISHMENT_CONTACT_EMAIL'] ?? null),
            'agent_details' => $this->nullable($row['AGENT_DETAILS'] ?? null),
            'registrant_contact_name' => $this->nullable($row['REGISTRANT_CONTACT_NAME'] ?? null),
            'registrant_contact_email' => $this->nullable($row['REGISTRANT_CONTACT_EMAIL'] ?? null),
        ];

        $existing = FdaEstablishment::query()->firstOrNew(['fei_number' => $fei]);
        $wasNew = ! $existing->exists;
        $existing->fillFromFda($attributes);

        if ($wasNew) {
            $counts['inserted']++;
        } else {
            $counts['updated']++;
        }

        $this->syncOperations($existing, (string) ($row['OPERATIONS'] ?? ''));
    }

    private function syncOperations(FdaEstablishment $establishment, string $operations): void
    {
        $codes = [];

        foreach (explode(';', $operations) as $code) {
            $code = strtoupper(trim($code));
            if ($code !== '') {
                $codes[$code] = $code;
            }
        }

        $codes = array_values($codes);

        foreach ($codes as $code) {
            FdaEstablishmentOperation::query()->firstOrCreate([
                'fda_establishment_id' => $establishment->id,
                'operation_code' => $code,
            ]);
        }
    }

    private function parseExpiration(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('m/d/Y', $value)->startOfDay();
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function fileHash(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = FdaDecrsDataset::toUtf8($value);

        return $value === '' ? null : $value;
    }

    private function clip(?string $value, int $max): ?string
    {
        $value = $this->nullable($value);

        if ($value === null || mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }

    private function iso2(?string $value): ?string
    {
        $value = $this->nullable($value);

        return ($value !== null && strlen($value) === 2) ? strtoupper($value) : null;
    }
}
