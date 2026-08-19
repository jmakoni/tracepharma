<?php

namespace App\Console\Commands;

use App\Actions\Tenants\EnsureTenantOwner;
use App\Actions\Tenants\ProvisionTenantOnEnvironment;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\TenantProfile;
use App\Support\TenantHostname;
use Illuminate\Console\Command;

class ProvisionTenantCommand extends Command
{
    protected $signature = 'tracepharma:provision-tenant
        {name : Display name}
        {slug : Tenant slug}
        {--profile=pharmacy : Tenant profile value}
        {--environment= : stage or prod (omit to provision the pair)}
        {--pair : Provision both stage and prod}
        {--owner-name= : Initial owner name}
        {--owner-email= : Initial owner email}
        {--owner-password= : Initial owner password}';

    protected $description = 'Create isolated stage and/or prod tenant hosts from a slug';

    public function handle(
        ProvisionTenantPair $pair,
        ProvisionTenantOnEnvironment $onEnvironment,
        EnsureTenantOwner $ensureOwner,
    ): int {
        $slug = strtolower((string) $this->argument('slug'));
        $profile = TenantProfile::from((string) $this->option('profile'));
        $attributes = [
            'name' => (string) $this->argument('name'),
            'profile' => $profile,
            'status' => 'active',
        ];
        $owner = $this->ownerFromOptions();

        $environment = $this->option('environment');
        $provisionPair = (bool) $this->option('pair') || $environment === null || $environment === '';

        if ($provisionPair) {
            $prod = $pair->create($slug, $attributes, [], $owner);
            $this->info('Provisioned pair for '.$slug.': '.TenantHostname::pairHint($slug));
            $this->info('Prod tenant '.$prod->id);

            return self::SUCCESS;
        }

        $environment = TenantHostname::assertPairEnvironment((string) $environment);
        $tenant = $onEnvironment->provision($slug, $attributes, $environment);
        if ($owner !== null) {
            $ensureOwner->handle($tenant, $owner);
        }
        $this->info('Tenant '.$tenant->id.' provisioned for '.TenantHostname::forSlug($slug, $environment));

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, email: string, password: string}|null
     */
    private function ownerFromOptions(): ?array
    {
        $name = $this->option('owner-name');
        $email = $this->option('owner-email');
        $password = $this->option('owner-password');

        if (! is_string($name) || $name === ''
            || ! is_string($email) || $email === ''
            || ! is_string($password) || $password === '') {
            return null;
        }

        return [
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ];
    }
}
