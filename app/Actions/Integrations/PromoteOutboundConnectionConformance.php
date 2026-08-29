<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\OutboundConformanceState;
use App\Models\OutboundConnection;
use App\Models\User;
use App\Support\Auth\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class PromoteOutboundConnectionConformance
{
    public function promoteOneStep(OutboundConnection $connection, User $actor): OutboundConnection
    {
        $from = $this->currentState($connection);
        $to = $from->next();

        if ($to === null) {
            throw new InvalidArgumentException(
                'Outbound connection conformance is already at the final state and cannot be promoted further.',
            );
        }

        $connection->allowConformanceTransition = true;
        $connection->forceFill([
            'conformance_state' => $to->value,
        ])->save();
        $connection->allowConformanceTransition = false;

        activity()
            ->performedOn($connection)
            ->causedBy($actor)
            ->withProperties([
                'from' => $from->value,
                'to' => $to->value,
                'reason' => null,
            ])
            ->log('outbound_connection_conformance_promoted');

        return $connection->refresh();
    }

    public function breakGlassToLive(OutboundConnection $connection, User $actor, string $reason): OutboundConnection
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A non-empty reason is required for break-glass to live.');
        }

        if (! $actor->can(Permissions::IntegrationsBreakGlass)) {
            throw new AuthorizationException(
                'Break-glass to live requires the integrations break-glass permission.',
            );
        }

        $from = $this->currentState($connection);
        $to = OutboundConformanceState::Live;

        if ($from === $to) {
            throw new InvalidArgumentException(
                'Outbound connection conformance is already live.',
            );
        }

        $connection->allowConformanceTransition = true;
        $connection->forceFill([
            'conformance_state' => $to->value,
        ])->save();
        $connection->allowConformanceTransition = false;

        activity()
            ->performedOn($connection)
            ->causedBy($actor)
            ->withProperties([
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
            ])
            ->log('outbound_connection_conformance_break_glass');

        return $connection->refresh();
    }

    private function currentState(OutboundConnection $connection): OutboundConformanceState
    {
        $raw = $connection->getAttribute('conformance_state');

        if ($raw instanceof OutboundConformanceState) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            return OutboundConformanceState::from($raw);
        }

        return OutboundConformanceState::Test;
    }
}
