<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Admin\AdminActivityLogger;
use App\Support\Auth\Permissions;
use App\Support\Tenancy\TenantAccess;
use DomainException;
use Stancl\Tenancy\Database\Models\ImpersonationToken;

final class StartTenantUserImpersonation
{
    public function __construct(
        private AdminActivityLogger $activityLogger,
    ) {}

    public function execute(Admin $admin, Tenant $tenant, string $userId, string $reason, ?string $ip = null): string
    {
        if (! $admin->can(Permissions::TenantsManage)) {
            abort(403);
        }

        if (! TenantAccess::isActive($tenant)) {
            throw new DomainException('Cannot impersonate users on a suspended tenant.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('An impersonation reason is required.');
        }

        $target = $tenant->run(function () use ($userId): ?User {
            return User::query()->find($userId);
        });

        if (! $target instanceof User) {
            throw new DomainException('User not found in tenant.');
        }

        $domain = $tenant->domains()->orderBy('id')->value('domain');
        if (! is_string($domain) || $domain === '') {
            throw new DomainException('Tenant has no domain.');
        }

        $ip = $ip ?? request()->ip();

        $token = ImpersonationToken::create([
            'tenant_id' => $tenant->getTenantKey(),
            'admin_id' => $admin->getKey(),
            'reason' => $reason,
            'admin_ip' => $ip,
            'user_id' => (string) $target->getKey(),
            'redirect_url' => '/',
            'auth_guard' => 'web',
        ]);

        $this->activityLogger->log('tenant_user_impersonation_started', $admin, [
            'tenant_id' => $tenant->getTenantKey(),
            'target_user_id' => (string) $target->getKey(),
            'target_user_email' => $target->email,
            'ip' => $ip,
            'reason' => $reason,
            'token_hash' => substr(hash('sha256', $token->token), 0, 16),
        ]);

        return 'https://'.$domain.'/impersonate/'.$token->token;
    }
}
