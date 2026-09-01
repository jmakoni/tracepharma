<?php

namespace App\Models;

use App\Support\Tenancy\TenantAccess;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasRoles;
    use Notifiable;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'oidc_issuer',
        'oidc_subject',
        'avatar_url',
        'preferences',
        'terms_accepted_at',
        'terms_version',
        'privacy_accepted_at',
        'privacy_version',
        'legal_notice_started_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'legal_notice_started_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function dashboardWidgetPreferences(): array
    {
        $widgets = data_get($this->preferencesBag(), 'dashboard_widgets', []);

        if (! is_array($widgets)) {
            return [];
        }

        $normalized = [];

        foreach ($widgets as $key => $on) {
            if (is_string($key)) {
                $normalized[$key] = (bool) $on;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, bool>  $widgets
     */
    public function setDashboardWidgetPreferences(array $widgets): void
    {
        $preferences = $this->preferencesBag();
        $preferences['dashboard_widgets'] = $widgets;
        $this->preferences = $preferences;
    }

    public function hasDashboardWidgetPreferences(): bool
    {
        return is_array($this->preferencesBag())
            && array_key_exists('dashboard_widgets', $this->preferencesBag());
    }

    /**
     * @return array<string, mixed>
     */
    private function preferencesBag(): array
    {
        $raw = $this->preferences;

        return is_array($raw) ? $raw : [];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! in_array($panel->getId(), ['app', 'knowledge-base'], true)) {
            return false;
        }

        return TenantAccess::isActive();
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url ? Storage::disk('public')->url($this->avatar_url) : null;
    }

    /**
     * @return BelongsToMany<Site, $this>
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * @param  list<int>  $siteIds
     */
    public function syncSites(array $siteIds, ?int $defaultSiteId = null): void
    {
        $siteIds = array_values(array_unique(array_map(intval(...), $siteIds)));

        if ($defaultSiteId !== null && ! in_array($defaultSiteId, $siteIds, true)) {
            $defaultSiteId = null;
        }

        if ($defaultSiteId === null && count($siteIds) === 1) {
            $defaultSiteId = $siteIds[0];
        }

        $syncData = [];

        foreach ($siteIds as $siteId) {
            $syncData[$siteId] = ['is_default' => $siteId === $defaultSiteId];
        }

        $this->sites()->sync($syncData);
    }
}
