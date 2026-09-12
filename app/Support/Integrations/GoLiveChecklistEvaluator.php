<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Models\ConnectionGoLiveChecklist;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use Illuminate\Support\Arr;

/**
 * Computes go-live checklist step states for a connection. Steps are either
 * auto-detected from live data or manually marked on the checklist record.
 */
class GoLiveChecklistEvaluator
{
    /**
     * @return array<string, string> step key => label, in display order
     */
    public function stepsDefinition(string $connectionType): array
    {
        if ($connectionType === ConnectionGoLiveChecklist::TYPE_INBOUND) {
            return [
                'credentials_set' => 'Credentials configured',
                'first_doc_received' => 'First document received',
                'exceptions_clear' => 'No open exceptions',
                'signed_off' => 'Go-live sign-off',
            ];
        }

        return [
            'profile_selected' => 'Network profile selected',
            'credentials_set' => 'Credentials configured',
            'probe_ok' => 'Connectivity probe passed',
            'first_doc_validated' => 'First document validated',
            'exceptions_clear' => 'No open exceptions',
            'signed_off' => 'Go-live sign-off',
        ];
    }

    /**
     * @return list<array{key: string, label: string, done: bool, source: string, detail: string}>
     */
    public function evaluate(OutboundConnection|InboundConnection $connection): array
    {
        $type = $connection instanceof OutboundConnection
            ? ConnectionGoLiveChecklist::TYPE_OUTBOUND
            : ConnectionGoLiveChecklist::TYPE_INBOUND;

        $checklist = ConnectionGoLiveChecklist::forConnection($type, (int) $connection->getKey());

        $states = [];

        foreach ($this->stepsDefinition($type) as $key => $label) {
            [$done, $source, $detail] = $this->evaluateStep($key, $connection, $checklist);

            $states[] = [
                'key' => $key,
                'label' => $label,
                'done' => $done,
                'source' => $source,
                'detail' => $detail,
            ];
        }

        return $states;
    }

    public function isComplete(OutboundConnection|InboundConnection $connection): bool
    {
        foreach ($this->evaluate($connection) as $step) {
            if (! $step['done']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    private function evaluateStep(
        string $key,
        OutboundConnection|InboundConnection $connection,
        ConnectionGoLiveChecklist $checklist,
    ): array {
        $manual = $checklist->manualStep($key);

        if ($manual !== null) {
            return [true, 'manual', 'Marked complete'.($manual['note'] ? ': '.$manual['note'] : '.')];
        }

        return match ($key) {
            'profile_selected' => $this->profileSelected($connection),
            'credentials_set' => $this->credentialsSet($connection),
            'probe_ok' => $this->probeOk($connection),
            'first_doc_validated' => $this->firstDoc($connection, 'validated'),
            'first_doc_received' => $this->firstDoc($connection, null),
            'exceptions_clear' => $this->exceptionsClear($connection),
            'signed_off' => [
                $checklist->isSignedOff(),
                'auto',
                $checklist->isSignedOff()
                    ? 'Signed off '.$checklist->signed_off_at->toDateTimeString().'.'
                    : 'A go-live sign-off is required.',
            ],
            default => [false, 'auto', 'Unknown step.'],
        };
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    private function profileSelected(OutboundConnection|InboundConnection $connection): array
    {
        if (! $connection instanceof OutboundConnection) {
            return [true, 'auto', 'Not applicable to inbound connections.'];
        }

        if ($connection->network_profile_id !== null) {
            return [true, 'auto', 'Network profile attached.'];
        }

        // Direct-partner connections use a custom endpoint instead of a profile.
        $settings = (array) ($connection->settings ?? []);

        return filled(Arr::get($settings, 'endpoint_url') ?? Arr::get($settings, 'webhook_url'))
            ? [true, 'auto', 'Direct-partner custom endpoint configured.']
            : [false, 'auto', 'Attach a network profile (or configure a custom endpoint).'];
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    private function credentialsSet(OutboundConnection|InboundConnection $connection): array
    {
        $settings = (array) ($connection->settings ?? []);
        $transport = (string) ($connection->transport?->value ?? $connection->transport ?? '');

        $ok = match ($transport) {
            'https' => filled(Arr::get($settings, 'endpoint_url') ?? Arr::get($settings, 'webhook_url'))
                || ($connection instanceof OutboundConnection && $connection->network_profile_id !== null),
            'as2' => filled(Arr::get($settings, 'signing_cert_pem')) || filled(Arr::get($settings, 'as2_id')),
            'sftp' => filled(Arr::get($settings, 'host')) || filled(Arr::get($settings, 'sftp_host')),
            default => $settings !== [],
        };

        return $ok
            ? [true, 'auto', 'Transport credentials present.']
            : [false, 'auto', 'Transport credentials are missing.'];
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    private function probeOk(OutboundConnection|InboundConnection $connection): array
    {
        $ok = (bool) Arr::get((array) ($connection->settings ?? []), 'last_probe_ok', false);
        $at = Arr::get((array) ($connection->settings ?? []), 'last_probe_at');

        if ($ok) {
            return [true, 'auto', 'Probe passed'.(is_string($at) ? " at {$at}." : '.')];
        }

        return [false, 'auto', 'Run Test connectivity and get a passing probe.'];
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    private function firstDoc(OutboundConnection|InboundConnection $connection, ?string $status): array
    {
        $column = $connection instanceof OutboundConnection ? 'outbound_connection_id' : 'inbound_connection_id';

        $query = EpcisDocument::query()->where($column, $connection->getKey());

        if ($status !== null) {
            $query->where('status', $status);
        }

        $exists = $query->exists();

        return $exists
            ? [true, 'auto', 'Document traffic confirmed.']
            : [false, 'auto', 'No documents through this connection yet.'];
    }

    /**
     * @return array{0: bool, 1: string, 2: string}
     */
    private function exceptionsClear(OutboundConnection|InboundConnection $connection): array
    {
        $column = $connection instanceof OutboundConnection ? 'outbound_connection_id' : 'inbound_connection_id';

        $open = EpcisException::query()
            ->whereNull('resolved_at')
            ->whereIn('document_id', EpcisDocument::query()->where($column, $connection->getKey())->select('id'))
            ->count();

        return $open === 0
            ? [true, 'auto', 'No open exceptions on connection documents.']
            : [false, 'auto', "{$open} open exception(s) on documents through this connection."];
    }
}
