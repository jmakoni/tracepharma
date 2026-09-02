<?php

declare(strict_types=1);

namespace App\Actions\L3;

use App\Enums\TenantProfile;
use App\Exceptions\GuardianLotCloseConflictException;
use App\Exceptions\GuardianLotCloseDisabledException;
use App\Exceptions\GuardianLotCloseUnauthorizedException;
use App\Jobs\L3\ConvertAndAcceptGuardianLotJob;
use App\Models\L3\L3LotFeed;
use App\Models\Tenant;
use App\Services\L3\GuardianDataFeedParser;
use App\Support\Epcis\EpcisCacheLock;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accept a raw Guardian (Systech) lot-close `DataFeed` POST: archive the raw
 * XML once, create the feed ledger row, and dispatch conversion.
 *
 * Idempotent on Envelope/MessageID first, then raw-payload SHA-256 — a replay of a
 * feed already `processing`/`accepted` returns the existing row without a
 * second dispatch; a replay of a `received`/`failed` row re-dispatches so a
 * stuck or previously-failed feed can be retried by resubmission. A feed
 * stuck `processing` for longer than {@see self::STALE_PROCESSING_SECONDS}
 * (a worker died mid-run) is treated as re-dispatchable too. Same MessageID with
 * a different body is a conflict (409). Lookups never OR the two keys.
 *
 * The create path locks on the raw-payload SHA-256 first, then on the
 * MessageID: identical bytes always hash identically, so the SHA-256 lock
 * alone serializes true duplicate resends; the nested MessageID lock also
 * covers the degenerate case of two different bodies reusing the same
 * MessageID, so neither key can race past the "does a feed already exist"
 * check independently.
 */
final class ReceiveGuardianLotFeed
{
    /**
     * A `processing` feed older than this is treated as abandoned (worker died)
     * and may be redispatched. Keep {@see ConvertAndAcceptGuardianLotJob::$uniqueFor}
     * ≤ this value so the unique lock does not outlive the stale window.
     */
    public const STALE_PROCESSING_SECONDS = 600;

    public function __construct(
        private readonly GuardianDataFeedParser $parser,
    ) {}

    public function handle(string $rawXml, ?string $providedApiKey): L3LotFeed
    {
        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new \DomainException('Guardian lot-close ingest requires an initialized tenant.');
        }

        $settings = TenantSettings::forTenant($tenant);

        if (! $settings->l3Enabled() || ! $settings->l3GuardianLotCloseEnabled()) {
            throw new GuardianLotCloseDisabledException('Guardian lot-close inbound is not enabled for this organization.');
        }

        // Renders a 403 directly (request is api/*) rather than throwing a
        // Disabled exception: kill switches are an operational circuit-breaker,
        // distinct from the feature-enablement checks above.
        TenantKillSwitches::forTenant($tenant)->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);

        $profile = $tenant->profile instanceof TenantProfile ? $tenant->profile : null;
        if ($profile !== TenantProfile::Manufacturer) {
            throw new GuardianLotCloseDisabledException('Guardian lot-close inbound is only available for Manufacturer organizations.');
        }

        $provider = $settings->l3Provider();
        if ($provider === null || strcasecmp($provider, 'systech') !== 0) {
            throw new GuardianLotCloseDisabledException('Guardian lot-close inbound requires the Systech L3 provider.');
        }

        $configuredKey = (string) ($settings->l3ApiKey() ?? '');
        $provided = (string) ($providedApiKey ?? '');
        if ($configuredKey === '' || $provided === '' || ! hash_equals($configuredKey, $provided)) {
            throw new GuardianLotCloseUnauthorizedException('Invalid Guardian lot-close API key.');
        }

        if (trim($rawXml) === '') {
            throw new \InvalidArgumentException('Guardian DataFeed body is empty.');
        }

        $sha256 = hash('sha256', $rawXml);

        $tmpPath = tempnam(sys_get_temp_dir(), 'guardian_lot_');
        if ($tmpPath === false) {
            throw new \RuntimeException('Unable to create temporary file for Guardian DataFeed.');
        }

        file_put_contents($tmpPath, $rawXml);

        try {
            $messageId = $this->parser->peekMessageId($tmpPath);
            if (blank($messageId)) {
                throw new \InvalidArgumentException('Guardian DataFeed is missing Envelope/MessageID.');
            }

            $tenantId = (string) $tenant->getKey();

            return EpcisCacheLock::lock($this->shaLockKey($tenantId, $sha256), 30)->block(
                10,
                fn (): L3LotFeed => EpcisCacheLock::lock($this->messageIdLockKey($tenantId, (string) $messageId), 30)->block(
                    10,
                    function () use ($messageId, $sha256, $tmpPath, $tenantId): L3LotFeed {
                        // MessageID first, then SHA alone — never OR them: an OR
                        // `first()` can return a sha-matched other feed and skip the
                        // MessageID≠SHA conflict (409).
                        $byMessageId = L3LotFeed::query()
                            ->where('message_id', $messageId)
                            ->first();

                        if ($byMessageId !== null) {
                            if ((string) $byMessageId->file_sha256 !== (string) $sha256) {
                                throw new GuardianLotCloseConflictException(
                                    'Guardian DataFeed MessageID already exists with a different payload body.'
                                );
                            }

                            if ($this->shouldRedispatch($byMessageId)) {
                                ConvertAndAcceptGuardianLotJob::dispatch($tenantId, (int) $byMessageId->getKey())
                                    ->onQueue('epcis');
                            }

                            return $byMessageId;
                        }

                        $bySha = L3LotFeed::query()
                            ->where('file_sha256', $sha256)
                            ->first();

                        if ($bySha !== null) {
                            if ($this->shouldRedispatch($bySha)) {
                                ConvertAndAcceptGuardianLotJob::dispatch($tenantId, (int) $bySha->getKey())
                                    ->onQueue('epcis');
                            }

                            return $bySha;
                        }

                        $disk = (string) config('tracepharma.epcis.payload_disk', 'local');
                        $payloadPath = 'l3/guardian/'.(string) Str::uuid().'.xml';

                        $this->storePayload($disk, $payloadPath, $tmpPath);

                        $feed = L3LotFeed::query()->create([
                            'message_id' => $messageId,
                            'file_sha256' => $sha256,
                            'payload_disk' => $disk,
                            'payload_path' => $payloadPath,
                            'status' => 'received',
                        ]);

                        ConvertAndAcceptGuardianLotJob::dispatch($tenantId, (int) $feed->getKey())
                            ->onQueue('epcis');

                        return $feed;
                    },
                ),
            );
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * `processing`/`accepted` are not re-dispatched — except a `processing` row
     * stuck past {@see self::STALE_PROCESSING_SECONDS}: the worker that picked it
     * up is presumed dead, so a resubmission is allowed to kick off a fresh run
     * rather than being silently swallowed forever.
     */
    private function shouldRedispatch(L3LotFeed $existing): bool
    {
        if (! in_array($existing->status, ['processing', 'accepted'], true)) {
            return true;
        }

        if ($existing->status !== 'processing') {
            return false;
        }

        $updatedAt = $existing->updated_at;

        return $updatedAt !== null && $updatedAt->lt(now()->subSeconds(self::STALE_PROCESSING_SECONDS));
    }

    private function storePayload(string $disk, string $payloadPath, string $absolutePath): void
    {
        $stream = fopen($absolutePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Unable to read Guardian DataFeed payload: {$absolutePath}");
        }

        try {
            $filesystem = Storage::disk($disk);
            if (method_exists($filesystem, 'writeStream')) {
                if ($filesystem->writeStream($payloadPath, $stream) === false) {
                    throw new \RuntimeException("Unable to store Guardian DataFeed XML at {$payloadPath}");
                }

                return;
            }

            $filesystem->put($payloadPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function shaLockKey(string $tenantId, string $sha256): string
    {
        return 'guardian-lot-feed:'.$tenantId.':sha:'.$sha256;
    }

    private function messageIdLockKey(string $tenantId, string $messageId): string
    {
        return 'guardian-lot-feed:'.$tenantId.':msg:'.$messageId;
    }
}
