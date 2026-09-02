<?php

declare(strict_types=1);

namespace App\Services\Auth\Oidc;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Auth\OidcConnectionConfig;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

final class OidcIdentityResolver
{
    public function resolveTenantUser(SocialiteUser $socialiteUser, OidcConnectionConfig $config): User
    {
        $email = $this->requireEmail($socialiteUser);
        $subject = $this->requireSubject($socialiteUser);
        $issuer = rtrim($config->issuer, '/');

        $user = User::query()
            ->where('oidc_issuer', $issuer)
            ->where('oidc_subject', $subject)
            ->first();

        if ($user === null) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();
        }

        if ($user !== null) {
            $this->assertEmailDomainAllowed($email, $config);
            $this->assertOidcBindingCompatible($user, $issuer, $subject);

            $user->forceFill([
                'oidc_issuer' => $issuer,
                'oidc_subject' => $subject,
                'name' => $socialiteUser->getName() ?: $user->name,
                'email' => $email,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            return $user;
        }

        $this->assertEmailDomainAllowed($email, $config);

        $role = $this->jitRole($config);

        $user = User::query()->create([
            'name' => $socialiteUser->getName() ?: Str::before($email, '@'),
            'email' => $email,
            'password' => null,
            'oidc_issuer' => $issuer,
            'oidc_subject' => $subject,
            'email_verified_at' => now(),
        ]);

        $user->syncRoles([$role->value]);

        return $user;
    }

    public function resolveAdmin(SocialiteUser $socialiteUser, OidcConnectionConfig $config): Admin
    {
        $email = $this->requireEmail($socialiteUser);
        $subject = $this->requireSubject($socialiteUser);
        $issuer = rtrim($config->issuer, '/');

        $admin = Admin::query()
            ->where('oidc_issuer', $issuer)
            ->where('oidc_subject', $subject)
            ->first();

        if ($admin === null) {
            throw new \RuntimeException('No platform admin account is provisioned for this identity.');
        }

        $admin->forceFill([
            'oidc_issuer' => $issuer,
            'oidc_subject' => $subject,
            'name' => $socialiteUser->getName() ?: $admin->name,
        ])->save();

        return $admin;
    }

    private function requireEmail(SocialiteUser $socialiteUser): string
    {
        $email = $socialiteUser->getEmail();

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('OIDC identity did not include a valid email claim.');
        }

        return Str::lower(trim($email));
    }

    private function requireSubject(SocialiteUser $socialiteUser): string
    {
        $id = $socialiteUser->getId();

        if (! is_string($id) && ! is_numeric($id)) {
            throw new \RuntimeException('OIDC identity did not include a subject claim.');
        }

        $subject = trim((string) $id);

        if ($subject === '') {
            throw new \RuntimeException('OIDC identity did not include a subject claim.');
        }

        return $subject;
    }

    private function assertEmailDomainAllowed(string $email, OidcConnectionConfig $config): void
    {
        if ($config->allowedEmailDomains === []) {
            throw new \RuntimeException('SSO allowed email domains must be configured before sign-in.');
        }

        $domain = Str::lower(Str::after($email, '@'));

        if (! in_array($domain, $config->allowedEmailDomains, true)) {
            throw new \RuntimeException('Email domain is not allowed for SSO JIT provisioning.');
        }
    }

    private function assertOidcBindingCompatible(User $user, string $issuer, string $subject): void
    {
        if (filled($user->oidc_subject) && $user->oidc_subject !== $subject) {
            throw new \RuntimeException('OIDC identity subject does not match the existing account binding.');
        }

        $existingIssuer = filled($user->oidc_issuer) ? rtrim((string) $user->oidc_issuer, '/') : null;

        if ($existingIssuer !== null && $existingIssuer !== $issuer) {
            throw new \RuntimeException('OIDC identity issuer does not match the existing account binding.');
        }
    }

    private function jitRole(OidcConnectionConfig $config): TenantRole
    {
        if (filled($config->jitDefaultRole)) {
            $role = TenantRole::tryFrom((string) $config->jitDefaultRole);
            if ($role !== null) {
                return $role;
            }
        }

        $profile = TenantProfile::tryFrom((string) (tenant()?->profile ?? '')) ?? TenantProfile::Pharmacy;

        return TenantRole::jitDefaultForProfile($profile);
    }
}
