<?php

declare(strict_types=1);

namespace App\Services\Exceptions;

use App\Enums\TenantRole;
use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Notifications\ExceptionCreated;
use App\Notifications\ExceptionEscalated;
use App\Notifications\ExceptionUpdated;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class ExceptionNotificationDispatcher
{
    public function __construct(
        private readonly PlatformSupportNotificationDispatcher $platformDispatcher,
    ) {}

    public function dispatchCreated(ExceptionCase $exception): void
    {
        $recipients = $this->owners();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ExceptionCreated($exception));
        }

        $this->platformDispatcher->dispatchForCriticalException($exception);
    }

    public function dispatchUpdated(ExceptionCase $exception, string $action, User $actor): void
    {
        $recipients = collect();

        if ($exception->assignee !== null && (int) $exception->assignee->getKey() !== (int) $actor->getKey()) {
            $recipients->push($exception->assignee);
        }

        if ($recipients->isEmpty()) {
            $recipients = $this->owners()->reject(
                fn (User $user): bool => (int) $user->getKey() === (int) $actor->getKey(),
            );
        }

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients->unique('id')->values(), new ExceptionUpdated($exception, $action, $actor));
    }

    public function dispatchEscalated(ExceptionCase $exception): void
    {
        $recipients = $this->owners();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ExceptionEscalated($exception));
        }

        $this->platformDispatcher->dispatchForEscalation($exception);
    }

    /**
     * @return Collection<int, User>
     */
    public function owners(): Collection
    {
        try {
            return User::role(TenantRole::Owner->value)->get();
        } catch (RoleDoesNotExist|PermissionDoesNotExist) {
            return collect();
        }
    }
}
