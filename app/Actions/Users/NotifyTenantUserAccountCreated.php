<?php

namespace App\Actions\Users;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantUserAccountCreated;
use App\Support\Mail\MailTemplateCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Email a newly created tenant user that their account is ready.
 * Password is never included. Support Engineer seats tell the mailbox
 * holder to use Forgot password because the form password was discarded.
 */
final class NotifyTenantUserAccountCreated
{
    public function handle(User $user): void
    {
        $email = trim((string) $user->email);

        if ($email === '') {
            return;
        }

        $tenant = tenant();
        $tenantName = $tenant instanceof Tenant
            ? (string) ($tenant->name ?: 'your organization')
            : 'your organization';

        $loginHost = $this->loginHost($tenant);
        $loginUrl = $loginHost !== ''
            ? 'https://'.$loginHost
            : (string) url('/');

        $name = trim((string) $user->name);
        $firstName = $name !== '' ? (string) str($name)->before(' ') : $name;

        $variables = [
            'first_name' => $firstName !== '' ? $firstName : 'there',
            'tenant_name' => $tenantName,
            'user_email' => $email,
            'login_host' => $loginHost !== '' ? $loginHost : parse_url($loginUrl, PHP_URL_HOST),
            'login_url' => $loginUrl,
        ];

        $user->loadMissing('roles');
        $templateKey = $user->hasRole(TenantRole::SupportEngineer->value)
            ? MailTemplateCatalog::TenantUserSupportEngineerAccountCreated
            : MailTemplateCatalog::TenantUserAccountCreated;

        try {
            Notification::route('mail', $email)
                ->notify(new TenantUserAccountCreated($variables, $templateKey));
        } catch (Throwable $exception) {
            Log::error('Tenant user account-created mail failed.', [
                'tenant_id' => $tenant?->getKey(),
                'user_id' => $user->getKey(),
                'user_email' => $email,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function loginHost(?Tenant $tenant): string
    {
        if (! $tenant instanceof Tenant) {
            return '';
        }

        $domain = $tenant->domains->first()?->domain;

        return is_string($domain) ? $domain : '';
    }
}
