<?php

declare(strict_types=1);

namespace App\Filament\Auth\Concerns;

use App\Models\Concerns\HasAccountSecurity;
use App\Support\Auth\AccountSecuritySession;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Filament login with disable / lockout / session-version binding.
 *
 * @mixin Login
 */
trait AuthenticatesWithAccountSecurity
{
    public function authenticate(): ?LoginResponse
    {
        if (method_exists($this, 'ssoOnly') && $this->ssoOnly()) {
            throw ValidationException::withMessages([
                'data.email' => 'Password sign-in is disabled. Use single sign-on.',
            ]);
        }

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        /** @var SessionGuard $authGuard */
        $authGuard = Filament::auth();

        /** @var EloquentUserProvider $authProvider */
        $authProvider = $authGuard->getProvider();
        $credentials = $this->getCredentialsFromFormData($data);
        $remember = $data['remember'] ?? false;
        $timeboxDuration = (int) config('auth.timebox_duration', 200_000);

        $user = app(Timebox::class)->call(function (Timebox $timebox) use ($authProvider, $authGuard, $credentials, $remember): Authenticatable {
            $this->fireAttemptingEvent($authGuard, $credentials, $remember);

            $user = $authProvider->retrieveByCredentials($credentials);

            if ((! $user) || (! $authProvider->validateCredentials($user, $credentials))) {
                $this->userUndertakingMultiFactorAuthentication = null;

                if ($user !== null && $this->usesAccountSecurity($user)) {
                    /** @phpstan-ignore-next-line */
                    $user->recordFailedLogin();
                    $user->refresh();

                    if (! $user->isUsable()) {
                        $this->fireFailedEvent($authGuard, $user, $credentials);
                        $this->throwAccountSecurityValidationException($user);
                    }
                }

                $this->fireFailedEvent($authGuard, $user, $credentials);
                $this->throwFailureValidationException();
            }

            if ($this->usesAccountSecurity($user) && ! $user->isUsable()) {
                $this->fireFailedEvent($authGuard, $user, $credentials);
                $this->throwAccountSecurityValidationException($user);
            }

            $timebox->returnEarly();

            return $user;
        }, $timeboxDuration);

        $needsMultiFactorChallenge = app(Timebox::class)->call(function (Timebox $timebox) use ($user): bool {
            if (
                filled($this->userUndertakingMultiFactorAuthentication) &&
                (decrypt($this->userUndertakingMultiFactorAuthentication) === $user->getAuthIdentifier())
            ) {
                if ($this->isMultiFactorChallengeRateLimited($user)) {
                    return true;
                }

                $this->multiFactorChallengeForm->validate();

                return false;
            }

            foreach (Filament::getMultiFactorAuthenticationProviders() as $multiFactorAuthenticationProvider) {
                if (! $multiFactorAuthenticationProvider->isEnabled($user)) {
                    continue;
                }

                $this->userUndertakingMultiFactorAuthentication = encrypt($user->getAuthIdentifier());

                if ($multiFactorAuthenticationProvider instanceof HasBeforeChallengeHook) {
                    $multiFactorAuthenticationProvider->beforeChallenge($user);
                }

                break;
            }

            if (filled($this->userUndertakingMultiFactorAuthentication)) {
                $this->multiFactorChallengeForm->fill();

                return true;
            }

            return false;
        }, $timeboxDuration);

        if ($needsMultiFactorChallenge) {
            return null;
        }

        if (! $authGuard->attemptWhen($credentials, function (Authenticatable $user): bool {
            if (! ($user instanceof FilamentUser)) {
                return true;
            }

            return $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
        }, $remember)) {
            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException();
        }

        if ($this->usesAccountSecurity($user)) {
            /** @phpstan-ignore-next-line */
            $user->clearFailedLogins();
            AccountSecuritySession::bind($user);
        }

        session()->forget('auth.via');
        session()->regenerate();

        if ($this->usesAccountSecurity($user)) {
            AccountSecuritySession::bind($user);
        }

        return app(LoginResponse::class);
    }

    private function usesAccountSecurity(Authenticatable $user): bool
    {
        return in_array(HasAccountSecurity::class, class_uses_recursive($user), true);
    }

    private function throwAccountSecurityValidationException(Authenticatable $user): never
    {
        /** @phpstan-ignore-next-line */
        $message = $user->authenticationFailureMessage();

        throw ValidationException::withMessages([
            'data.email' => $message,
        ]);
    }
}
