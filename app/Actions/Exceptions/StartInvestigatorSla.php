<?php

namespace App\Actions\Exceptions;

use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Support\Exceptions\InvestigatorSlaClock;

/**
 * Send the existing portal email. Display uses due_at as the 72h overlay
 * once a send succeeds. due_at is never pulled earlier than an existing
 * future deadline.
 */
final class StartInvestigatorSla
{
    public function __construct(
        private readonly SendDscsaExceptionEmail $sendDscsaExceptionEmail,
    ) {}

    /**
     * @return array{sent: bool, portal_url?: string, error?: string}
     */
    public function handle(ExceptionCase $case, User $actor): array
    {
        $result = $this->sendDscsaExceptionEmail->execute($case, $actor);

        if (($result['sent'] ?? false) !== true) {
            return $result;
        }

        $deadline = now()->addHours(InvestigatorSlaClock::HOURS);

        if ($case->due_at === null || $case->due_at->isPast()) {
            $case->forceFill(['due_at' => $deadline])->save();
        }

        return $result;
    }
}
