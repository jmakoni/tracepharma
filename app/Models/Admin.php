<?php

namespace App\Models;

use App\Models\Concerns\HasAccountSecurity;
use App\Models\Concerns\HasForcedPasswordChange;
use Database\Factories\AdminFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<AdminFactory> */
    use HasAccountSecurity;

    use HasFactory;
    use HasForcedPasswordChange;
    use HasRoles;
    use Notifiable;
    use TwoFactorAuthenticatable;

    protected $table = 'admins';

    /**
     * Spatie roles/permissions for the admin panel guard.
     */
    protected string $guard_name = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'disabled_reason',
        'oidc_issuer',
        'oidc_subject',
        'directory_object_id',
        'user_principal_name',
        'employee_id',
        'given_name',
        'surname',
        'job_title',
        'department',
        'company_name',
        'office_location',
        'mobile_phone',
        'business_phone',
        'directory_groups',
        'directory_synced_at',
        'avatar_url',
        'preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'preferences' => 'array',
            'directory_groups' => 'array',
            'directory_synced_at' => 'datetime',
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
        if (! in_array($panel->getId(), ['admin', 'admin-knowledge-base'], true)) {
            return false;
        }

        return $this->isUsable();
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url ? Storage::disk('public')->url($this->avatar_url) : null;
    }
}
