<?php

namespace Tests\Unit\Support\Floor;

use App\Support\Floor\HandheldPwa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandheldPwaTest extends TestCase
{
    #[Test]
    public function allows_tracepharma_and_internal_vatengi_hosts(): void
    {
        $this->assertTrue(HandheldPwa::isAllowedHost('demo2.internal.vatengi.com'));
        $this->assertTrue(HandheldPwa::isAllowedHost('acme.tracepharma.io'));
        $this->assertTrue(HandheldPwa::isAllowedHost('floor.internal.vatengi.com'));
        $this->assertTrue(HandheldPwa::isAllowedHost('floor.tracepharma.io'));
    }

    #[Test]
    public function rejects_arbitrary_and_reserved_hosts(): void
    {
        $this->assertFalse(HandheldPwa::isAllowedHost('evil.com'));
        $this->assertFalse(HandheldPwa::isAllowedHost('demo2.example.test'));
        $this->assertFalse(HandheldPwa::isAllowedHost('not a host'));
        $this->assertFalse(HandheldPwa::isAllowedHost('user@demo2.internal.vatengi.com'));
        $this->assertFalse(HandheldPwa::isAllowedHost('demo2.internal.vatengi.com/login'));
    }

    #[Test]
    public function localhost_is_only_allowed_in_local_or_testing(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertTrue(HandheldPwa::isAllowedHost('localhost'));
    }

    #[Test]
    public function normalize_host_strips_scheme_and_rejects_unknown(): void
    {
        $this->assertSame('demo2.internal.vatengi.com', HandheldPwa::normalizeHost('https://demo2.internal.vatengi.com/floor'));
        $this->assertSame('demo2.internal.vatengi.com', HandheldPwa::normalizeHost('demo2.internal.vatengi.com'));
        $this->assertNull(HandheldPwa::normalizeHost('https://evil.com'));
        $this->assertNull(HandheldPwa::normalizeHost(''));
    }

    #[Test]
    public function floor_login_url_uses_https_except_localhost(): void
    {
        $this->assertSame('https://demo2.internal.vatengi.com/login', HandheldPwa::floorLoginUrl('demo2.internal.vatengi.com'));
        $this->assertSame('http://localhost/login', HandheldPwa::floorLoginUrl('localhost'));
    }

    #[Test]
    public function asset_links_target_the_floor_package(): void
    {
        config(['tracepharma.handheld.sha256_cert_fingerprints' => ['AA:BB']]);

        $links = HandheldPwa::assetLinks();

        $this->assertSame('android_app', $links[0]['target']['namespace']);
        $this->assertSame('com.tracepharma.floor', $links[0]['target']['package_name']);
        $this->assertSame(['AA:BB'], $links[0]['target']['sha256_cert_fingerprints']);
        $this->assertContains('delegate_permission/common.handle_all_urls', $links[0]['relation']);
    }
}
