<?php

declare(strict_types=1);

namespace App\Support\EpcisHub;

use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;

/**
 * Decides when an outbound connection sends through a TracePharma-owned edge
 * (network hub egress URL/token, AS2 station, SFTP drop) instead of
 * tenant-owned endpoint credentials. Tenant-owned values always win when present.
 */
class PlatformOutboundEgress
{
    /** @var list<string> */
    private const EGRESS_PROVIDERS = ['systech', 'unitrace'];

    public function __construct(
        private readonly EpcisHubPlatformConfig $platformConfig,
    ) {}

    /**
     * HTTPS egress: profile-linked systech/unitrace connection without an
     * endpoint override, while the platform edge for that network is configured.
     */
    public function usesPlatformEgress(OutboundConnection $connection): bool
    {
        return $this->edgeFor($connection) !== null;
    }

    /**
     * Edge URL + token for a platform-egress connection, null when egress does
     * not apply.
     *
     * @return array{url: string, token: ?string}|null
     */
    public function edgeFor(OutboundConnection $connection): ?array
    {
        if ($connection->transport !== OutboundTransport::Https) {
            return null;
        }

        $tracepharmaEdge = $this->tracepharmaHubEdge($connection);

        if ($tracepharmaEdge !== null) {
            return $tracepharmaEdge;
        }

        $provider = $this->egressProvider($connection);

        if ($provider === null) {
            return null;
        }

        $environment = $this->platformConfig->currentEnvironment();
        $url = $this->platformConfig->outboundUrl($environment, $provider);

        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'token' => $this->platformConfig->outboundToken($environment, $provider),
        ];
    }

    /**
     * Hub-linked AS2 connections without their own signing pair sign with the
     * platform station certificate (and send the station ID as AS2-From).
     */
    public function usesPlatformAs2Signing(OutboundConnection $connection): bool
    {
        if ($connection->transport !== OutboundTransport::As2 || ! $this->isHubLinked($connection)) {
            return false;
        }

        $credentials = is_array($connection->credentials) ? $connection->credentials : [];

        return ! (filled($credentials['signing_cert_pem'] ?? null) && filled($credentials['signing_key_pem'] ?? null));
    }

    /**
     * Hub-linked SFTP connections missing their own host/username fall back to
     * the platform SFTP drop credentials.
     */
    public function usesPlatformSftp(OutboundConnection $connection): bool
    {
        if ($connection->transport !== OutboundTransport::Sftp || ! $this->isHubLinked($connection)) {
            return false;
        }

        $credentials = is_array($connection->credentials) ? $connection->credentials : [];
        $settings = is_array($connection->settings) ? $connection->settings : [];

        $host = trim((string) ($credentials['host'] ?? $settings['host'] ?? ''));
        $username = trim((string) ($credentials['username'] ?? ''));

        return $host === '' || $username === '';
    }

    /**
     * TracePharma hub profiles: the platform owns both ends of the pipe, so the
     * send always carries the platform's current hub token for the profile's
     * environment. A token copy stored on the tenant connection goes stale on
     * rotation and strands every send with HTTP 401.
     *
     * @return array{url: string, token: ?string}|null
     */
    private function tracepharmaHubEdge(OutboundConnection $connection): ?array
    {
        if (! $this->isHubLinked($connection)) {
            return null;
        }

        $profile = $connection->networkProfile();

        if (strtolower(trim((string) ($profile?->network_slug ?? ''))) !== 'tracepharma') {
            return null;
        }

        $url = trim((string) ($profile?->endpoint_url ?? ''));

        if ($url === '') {
            return null;
        }

        $environment = is_string($profile?->environment) && $profile->environment !== ''
            ? $profile->environment
            : $this->platformConfig->currentEnvironment();

        return [
            'url' => $url,
            'token' => $this->platformConfig->hubToken($environment),
        ];
    }

    private function egressProvider(OutboundConnection $connection): ?string
    {
        if (! $this->isHubLinked($connection)) {
            return null;
        }

        $slug = strtolower(trim((string) ($connection->networkProfile()?->network_slug ?? '')));

        return in_array($slug, self::EGRESS_PROVIDERS, true) ? $slug : null;
    }

    private function isHubLinked(OutboundConnection $connection): bool
    {
        return $connection->network_profile_id !== null
            && ! $connection->override_endpoint
            && $connection->networkProfile() !== null;
    }
}
