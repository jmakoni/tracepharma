<?php

namespace App\Support;

use RuntimeException;

class TenantHostname
{
    /** @var list<string> */
    private const RESERVED_SLUGS = ['stage', 'prod', 'admin', 'www'];

    /** @var list<string> */
    public const PAIR_ENVIRONMENTS = ['stage', 'prod'];

    public static function baseDomain(): string
    {
        return (string) config('tracepharma.platform_base_domain', 'tracepharma.io');
    }

    public static function tenantEnvironment(): string
    {
        $environment = strtolower((string) config('tracepharma.tenant_environment', 'prod'));

        return in_array($environment, self::PAIR_ENVIRONMENTS, true) ? $environment : 'prod';
    }

    public static function forSlug(string $slug, string $environment = 'prod'): string
    {
        $environment = strtolower($environment);

        if (! in_array($environment, self::PAIR_ENVIRONMENTS, true)) {
            throw new RuntimeException('Tenant environment must be stage or prod.');
        }

        return strtolower($slug).'.'.$environment.'.'.self::baseDomain();
    }

    public static function forCurrentEnvironment(string $slug): string
    {
        return self::forSlug($slug, self::tenantEnvironment());
    }

    /**
     * @return list<string>
     */
    public static function pairForSlug(string $slug): array
    {
        return [
            self::forSlug($slug, 'stage'),
            self::forSlug($slug, 'prod'),
        ];
    }

    public static function pairHint(string $slug = '{slug}'): string
    {
        return $slug.'.stage.'.self::baseDomain().' and '.$slug.'.prod.'.self::baseDomain();
    }

    public static function looksLikePairHost(string $host): bool
    {
        $host = strtolower(trim($host));
        $base = preg_quote(self::baseDomain(), '/');

        return preg_match(
            '/^[a-z0-9]+(?:-[a-z0-9]+)*\.(?:stage|prod)\.'.$base.'$/',
            $host,
        ) === 1;
    }

    public static function dnsSlugPattern(): string
    {
        return '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    }

    public static function isReservedSlug(string $slug): bool
    {
        $slug = strtolower($slug);

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return true;
        }

        foreach (self::reservedHosts() as $host) {
            $label = strtolower(explode('.', $host, 2)[0]);

            if ($label !== '' && $label === $slug) {
                return true;
            }
        }

        return false;
    }

    public static function assertProvisionableSlug(string $slug): void
    {
        $slug = strtolower($slug);

        if ($slug === '' || preg_match(self::dnsSlugPattern(), $slug) !== 1) {
            throw new RuntimeException('Tenant slug must be a DNS label (lowercase letters, digits, and hyphens).');
        }

        if (self::isReservedSlug($slug)) {
            throw new RuntimeException('The slug '.$slug.' is reserved.');
        }
    }

    public static function assertPairEnvironment(string $environment): string
    {
        $environment = strtolower($environment);

        if (! in_array($environment, self::PAIR_ENVIRONMENTS, true)) {
            throw new RuntimeException('Tenant environment must be stage or prod.');
        }

        return $environment;
    }

    public static function isReservedHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return in_array($host, self::reservedHosts(), true);
    }

    /**
     * @return list<string>
     */
    public static function reservedHosts(): array
    {
        $hosts = [
            (string) config('tracepharma.central_domain'),
            (string) config('tracepharma.admin_domain'),
            (string) config('tracepharma.marketing_domain'),
            'stage.'.self::baseDomain(),
            'prod.'.self::baseDomain(),
        ];

        foreach (config('tenancy.central_domains', []) as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        foreach (config('tracepharma.demo_domains', []) as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }
}
