<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\App\Pages\AcceptLegalDocuments;
use App\Models\User;
use App\Support\Admin\TenantImpersonation;
use App\Support\Legal\LegalAcceptance;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsureLegalAcceptance
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (TenantImpersonation::isActive()) {
            return $next($request);
        }

        if (! LegalAcceptance::isGated($user)) {
            return $next($request);
        }

        if ($this->isAcceptOrLogoutPath($request)) {
            return $next($request);
        }

        if (! LegalAcceptance::isStale($user)) {
            return $next($request);
        }

        LegalAcceptance::ensureNoticeStarted($user);

        // Soft notice: allow the rest of the app (including Livewire) during grace.
        if (! LegalAcceptance::isHardBlocked($user)) {
            return $next($request);
        }

        // Hard-blocked: only Accept Legal Livewire may mutate — no blanket livewire/* exemption.
        if ($this->isAcceptLegalLivewireRequest($request)) {
            return $next($request);
        }

        return redirect()->guest($this->acceptUrl());
    }

    private function isAcceptOrLogoutPath(Request $request): bool
    {
        if ($request->routeIs(
            'filament.app.pages.accept-legal-documents',
            'filament.app.auth.logout',
        )) {
            return true;
        }

        if ($request->is('logout')) {
            return true;
        }

        $acceptPath = parse_url($this->acceptUrl(), PHP_URL_PATH);

        return is_string($acceptPath) && $acceptPath !== '' && $request->is(ltrim($acceptPath, '/'));
    }

    /**
     * Livewire updates hit livewire/* (not the Filament page route). Allow only when every
     * component in the payload is Accept Legal.
     */
    private function isAcceptLegalLivewireRequest(Request $request): bool
    {
        if (! $request->is('livewire/*') && ! $request->hasHeader('X-Livewire')) {
            return false;
        }

        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                return false;
            }

            $snapshotJson = $component['snapshot'] ?? null;

            if (! is_string($snapshotJson) || $snapshotJson === '') {
                return false;
            }

            $snapshot = json_decode($snapshotJson, true);

            if (! is_array($snapshot)) {
                return false;
            }

            $name = (string) ($snapshot['memo']['name'] ?? '');
            $path = (string) ($snapshot['memo']['path'] ?? '');

            $isAcceptComponent = str_contains($name, 'AcceptLegalDocuments')
                || str_ends_with($name, 'accept-legal-documents');
            $isAcceptPath = str_contains($path, 'accept-legal-documents');

            if (! $isAcceptComponent && ! $isAcceptPath) {
                return false;
            }
        }

        return true;
    }

    private function acceptUrl(): string
    {
        $previousPanel = Filament::getCurrentPanel();

        try {
            // Generate the App-panel accept URL without permanently switching the
            // current panel (isAcceptOrLogoutPath calls this on every gated request — a
            // leaked setCurrentPanel('app') breaks sibling panels like /help).
            Filament::setCurrentPanel(Filament::getPanel('app'));

            return AcceptLegalDocuments::getUrl(panel: 'app');
        } catch (Throwable) {
            return url('/accept-legal-documents');
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }
}
