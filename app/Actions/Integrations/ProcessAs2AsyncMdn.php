<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\OutboundTransport;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Support\Integrations\As2MdnDispositionParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProcessAs2AsyncMdn
{
    public function __construct(
        private readonly As2MdnDispositionParser $dispositionParser,
    ) {}

    /**
     * @return array{status: string, transmission_mdn_id: int|null}
     */
    public function handle(Request $request, OutboundConnection $connection, string $rawBody): array
    {
        if ($connection->transport !== OutboundTransport::As2 || ! $connection->is_active) {
            abort(404);
        }

        $originalMessageId = $this->resolveOriginalMessageId($request, $rawBody);

        if ($originalMessageId === null) {
            abort(422, 'AS2 MDN webhook is missing Original-Message-ID.');
        }

        $mdnStatus = $this->dispositionParser->mdnStatusFromBody($rawBody);

        return DB::transaction(function () use ($request, $connection, $rawBody, $originalMessageId, $mdnStatus): array {
            $mdn = TransmissionMdn::query()
                ->where('mdn_status', 'pending')
                ->where('mdn_payload->message_id', $originalMessageId)
                ->whereHas('document', fn ($query) => $query->where('outbound_connection_id', $connection->getKey()))
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($mdn === null) {
                $alreadyFinalized = TransmissionMdn::query()
                    ->whereIn('mdn_status', ['received', 'failed', 'superseded'])
                    ->where('mdn_payload->message_id', $originalMessageId)
                    ->whereHas('document', fn ($query) => $query->where('outbound_connection_id', $connection->getKey()))
                    ->exists();

                if ($alreadyFinalized) {
                    abort(409, 'AS2 MDN already processed.');
                }

                abort(404, 'No pending transmission MDN matched Original-Message-ID.');
            }

            $payload = array_merge($mdn->mdn_payload ?? [], [
                'async_webhook' => true,
                'content_type' => $request->header('Content-Type'),
                'headers' => $this->redactSensitiveHeaders($request->headers->all()),
                'body' => $rawBody,
                'disposition' => $this->dispositionParser->extractDisposition($rawBody),
            ]);

            if ($mdnStatus === null) {
                $mdn->forceFill([
                    'mdn_payload' => array_merge($payload, [
                        'disposition_unparseable' => true,
                    ]),
                ])->save();

                return [
                    'status' => 'received-unknown',
                    'transmission_mdn_id' => (int) $mdn->getKey(),
                ];
            }

            $mdn->forceFill([
                'mdn_status' => $mdnStatus,
                'mdn_received_at' => now(),
                'mdn_payload' => $payload,
            ])->save();

            return [
                'status' => $mdnStatus,
                'transmission_mdn_id' => (int) $mdn->getKey(),
            ];
        });
    }

    /**
     * @param  array<string, list<string|null>>  $headers
     * @return array<string, list<string|null>>
     */
    private function redactSensitiveHeaders(array $headers): array
    {
        $redactedKeys = ['authorization', 'x-as2-mdn-secret'];

        foreach ($headers as $name => $values) {
            if (! in_array(strtolower((string) $name), $redactedKeys, true)) {
                continue;
            }

            $headers[$name] = array_fill(0, count($values), '[REDACTED]');
        }

        return $headers;
    }

    private function resolveOriginalMessageId(Request $request, string $rawBody): ?string
    {
        $candidates = [
            $request->header('Original-Message-ID'),
            $request->header('Original-Message-Id'),
        ];

        if (preg_match('/Original-Message-ID:\s*(.+)/i', $rawBody, $matches) === 1) {
            $candidates[] = trim($matches[1]);
        }

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
