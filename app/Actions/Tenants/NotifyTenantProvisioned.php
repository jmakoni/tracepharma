<?php

namespace App\Actions\Tenants;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Notifications\TenantProvisionedOwner;
use App\Notifications\TenantProvisionedReceived;
use App\Support\TenantHostname;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NotifyTenantProvisioned
{
    /**
     * @param  array{name: string, email: string, password?: string}  $owner
     */
    public function handle(Tenant $prod, Tenant $stage, array $owner): void
    {
        $email = trim((string) ($owner['email'] ?? ''));

        if ($email === '') {
            return;
        }

        $slug = (string) ($prod->tenant_pair_slug ?: $stage->tenant_pair_slug);
        $prodHost = TenantHostname::forSlug($slug, 'prod');
        $stageHost = TenantHostname::forSlug($slug, 'stage');
        $name = trim((string) ($owner['name'] ?? ''));
        $firstName = $name !== '' ? (string) str($name)->before(' ') : $name;
        $profile = $prod->profile instanceof TenantProfile
            ? $prod->profile->label()
            : (string) ($prod->profile ?? '—');

        $ownerVariables = [
            'first_name' => $firstName !== '' ? $firstName : $name,
            'tenant_name' => (string) $prod->name,
            'owner_email' => $email,
            'prod_host' => $prodHost,
            'stage_host' => $stageHost,
            'prod_url' => 'https://'.$prodHost,
            'stage_url' => 'https://'.$stageHost,
        ];

        $opsVariables = [
            'tenant_name' => (string) $prod->name,
            'slug' => $slug,
            'profile' => $profile,
            'owner_name' => $name !== '' ? $name : '—',
            'owner_email' => $email,
            'prod_host' => $prodHost,
            'stage_host' => $stageHost,
        ];

        try {
            Notification::route('mail', $email)
                ->notify(new TenantProvisionedOwner($ownerVariables));

            $opsEmail = config('tracepharma.marketing.onboarding_notify_email')
                ?? config('tracepharma.marketing.demo_notify_email');

            if (filled($opsEmail)) {
                Notification::route('mail', $opsEmail)
                    ->notify(new TenantProvisionedReceived($opsVariables));
            }
        } catch (Throwable $exception) {
            Log::error('Tenant provisioned mail failed.', [
                'tenant_id' => $prod->getKey(),
                'owner_email' => $email,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
