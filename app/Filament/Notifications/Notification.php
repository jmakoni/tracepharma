<?php

declare(strict_types=1);

namespace App\Filament\Notifications;

use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-writes Filament toasts to the database notifications bell when a panel
 * user is authenticated. Mark scan/floor noise with {@see ephemeral()}.
 */
class Notification extends FilamentNotification
{
    protected bool $ephemeral = false;

    public function ephemeral(bool $ephemeral = true): static
    {
        $this->ephemeral = $ephemeral;

        return $this;
    }

    public function isEphemeral(): bool
    {
        return $this->ephemeral;
    }

    public function send(): static
    {
        if (! $this->ephemeral) {
            $recipient = $this->resolvePanelRecipient();

            if ($recipient !== null) {
                $this->sendToDatabase($recipient, isEventDispatched: true);
            }
        }

        return parent::send();
    }

    protected function resolvePanelRecipient(): Model|Authenticatable|null
    {
        try {
            $user = Filament::auth()->user();
        } catch (\Throwable) {
            return null;
        }

        if ($user instanceof Model || $user instanceof Authenticatable) {
            return $user;
        }

        return null;
    }
}
