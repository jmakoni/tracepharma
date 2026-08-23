<?php

namespace App\Actions\Exceptions;

use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Support\Exceptions\InvestigatorSlaClock;

/**
 * Send the existing portal email. Display uses the 72h overlay; due_at is
 * written only after a successful send, and never shortened.
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

        if ($case->due_at === null) {
            $case->forceFill(['due_at' => $deadline])->save();
        }

        return $result;
    }
}
