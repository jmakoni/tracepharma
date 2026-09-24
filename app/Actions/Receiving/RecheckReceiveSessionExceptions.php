<?php

declare(strict_types=1);

namespace App\Actions\Receiving;

use App\Actions\Exceptions\RecheckReceiveExceptionCondition;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Support\Receiving\ReceiveExceptionCondition;
use App\Support\Receiving\ReceiveSessionExceptionQuery;

final class RecheckReceiveSessionExceptions
{
    public function __construct(
        private readonly RecheckReceiveExceptionCondition $recheck,
    ) {}

    /**
     * @return list<ExceptionCase>
     */
    public function handle(ReceivingSession $session, ?User $actor = null): array
    {
        $cases = ReceiveSessionExceptionQuery::openCases(
            $session,
            ReceiveExceptionCondition::AUTO_RECHECK_TYPES,
        );

        $checked = [];
        foreach ($cases as $case) {
            $checked[] = $this->recheck->handle($case, $actor);
        }

        return $checked;
    }
}
