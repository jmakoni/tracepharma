<?php

namespace App\Console\Commands;

use App\Support\SanctumAbilities;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class GrantDispenseCheckAbilityCommand extends Command
{
    protected $signature = 'tracepharma:grant-dispense-check-ability
                            {--tenant=* : Tenant ID(s); default all}
                            {--dry-run : Report tokens that would be updated without writing}';

    protected $description = 'Append vrs:dispense-check to existing API tokens that do not yet have it';

    public function handle(): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totalUpdated = 0;

        foreach ($tenants as $tenant) {
            $updated = TenantRunner::run($tenant, fn (): int => $this->grantForCurrentTenant($dryRun));

            $totalUpdated += $updated;

            $this->line("[{$tenant->id}] {$tenant->name}: tokens_updated={$updated}");
        }

        $this->info(
            ($dryRun ? 'Dry run. ' : 'Done. ')
            ."tokens_updated={$totalUpdated}",
        );

        return self::SUCCESS;
    }

    private function grantForCurrentTenant(bool $dryRun): int
    {
        $updated = 0;

        PersonalAccessToken::query()
            ->orderBy('id')
            ->each(function (PersonalAccessToken $token) use ($dryRun, &$updated): void {
                $abilities = $this->normalizeAbilities($token->abilities);

                if (in_array(SanctumAbilities::VRS_DISPENSE_CHECK, $abilities, true)) {
                    return;
                }

                $newAbilities = array_values(array_unique([
                    ...$abilities,
                    SanctumAbilities::VRS_DISPENSE_CHECK,
                ]));

                if (! $dryRun) {
                    $token->forceFill(['abilities' => $newAbilities])->save();
                }

                $updated++;
            });

        return $updated;
    }

    /**
     * @return list<string>
     */
    private function normalizeAbilities(mixed $abilities): array
    {
        if (is_array($abilities)) {
            return array_values(array_filter(
                $abilities,
                static fn (mixed $ability): bool => is_string($ability) && $ability !== '',
            ));
        }

        if (! is_string($abilities) || $abilities === '') {
            return [];
        }

        $decoded = json_decode($abilities, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $ability): bool => is_string($ability) && $ability !== '',
        ));
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function resolveTenants(): Collection
    {
        /** @var list<string> $tenantIds */
        $tenantIds = $this->option('tenant');

        if ($tenantIds !== []) {
            return Tenant::query()->whereIn('id', $tenantIds)->orderBy('id')->get();
        }

        return Tenant::query()->orderBy('id')->get();
    }
}
