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

        if ($this->isExemptPath($request)) {
            return $next($request);
        }

        if (! LegalAcceptance::isStale($user)) {
            return $next($request);
        }

        LegalAcceptance::ensureNoticeStarted($user);

        if (! LegalAcceptance::isHardBlocked($user)) {
            return $next($request);
        }

        return redirect()->guest($this->acceptUrl());
    }

    private function isExemptPath(Request $request): bool
    {
        if ($request->routeIs(
            'filament.app.pages.accept-legal-documents',
            'filament.app.auth.logout',
        )) {
            return true;
        }

        if ($request->is('logout', 'livewire/*', 'filament/*')) {
            return true;
        }

        $acceptPath = parse_url($this->acceptUrl(), PHP_URL_PATH);

        return is_string($acceptPath) && $acceptPath !== '' && $request->is(ltrim($acceptPath, '/'));
    }

    private function acceptUrl(): string
    {
        $previousPanel = Filament::getCurrentPanel();

        try {
            // Generate the App-panel accept URL without permanently switching the
            // current panel (isExemptPath calls this on every gated request — a
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
