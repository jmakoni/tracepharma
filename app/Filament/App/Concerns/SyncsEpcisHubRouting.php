<?php

namespace App\Filament\App\Concerns;

use App\Actions\Integrations\RegisterEpcisHubRoute;
use App\Models\InboundConnection;
use Filament\Notifications\Notification;

trait SyncsEpcisHubRouting
{
    protected function syncHubRouting(InboundConnection $connection, bool $enabled): void
    {
        $registrar = app(RegisterEpcisHubRoute::class);

        try {
            if ($enabled) {
                $registrar->register($connection);
            } else {
                $registrar->unregister($connection);
            }
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Hub routing not updated')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
