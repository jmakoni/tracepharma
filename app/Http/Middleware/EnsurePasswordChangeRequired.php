<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Concerns\HasForcedPasswordChange;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force local-password users with must_change_password onto My Profile.
 * OIDC sessions (auth.via=oidc) are exempt — IdP owns the password.
 */
final class EnsurePasswordChangeRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user === null) {
            return $next($request);
        }

        if (! in_array(HasForcedPasswordChange::class, class_uses_recursive($user), true)) {
            return $next($request);
        }

        if (! $user->mustChangePassword()) {
            return $next($request);
        }

        if (session('auth.via') === 'oidc') {
            return $next($request);
        }

        if ($this->isAllowedWhilePasswordChangeRequired($request)) {
            return $next($request);
        }

        $panel = Filament::getCurrentOrDefaultPanel();
        $profilePanelId = match ($panel?->getId()) {
            'admin', 'admin-knowledge-base' => 'admin',
            default => 'app',
        };

        $url = Filament::getPanel($profilePanelId)->getUrl().'/my-profile';

        return redirect()->to($url);
    }

    private function isAllowedWhilePasswordChangeRequired(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        if ($name !== '' && (
            str_contains($name, '.auth.logout')
            || str_ends_with($name, '.pages.my-profile')
            || str_starts_with($name, 'livewire.')
        )) {
            return true;
        }

        $path = trim($request->path(), '/');

        return str_ends_with($path, 'my-profile')
            || str_contains($path, 'livewire')
            || str_ends_with($path, 'logout');
    }
}
