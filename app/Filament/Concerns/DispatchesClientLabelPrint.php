<?php

namespace App\Filament\Concerns;

trait DispatchesClientLabelPrint
{
    /**
     * Fire the browser print bridge when dispatch returned client-side jobs.
     *
     * @param  array{mode?: string, bridge?: string, jobs?: list<array<string, mixed>>}|null  $dispatch
     */
    protected function dispatchClientLabelPrint(?array $dispatch): void
    {
        if ($dispatch === null || ($dispatch['mode'] ?? '') !== 'client') {
            return;
        }

        $jobs = $dispatch['jobs'] ?? [];

        if ($jobs === []) {
            return;
        }

        $this->dispatch(
            'tp-client-print',
            bridge: (string) ($dispatch['bridge'] ?? ''),
            jobs: $jobs,
        );
    }
}
