<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\EpcisStoragePath;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisStoragePathTest extends TestCase
{
    #[Test]
    public function local_disk_keeps_short_epcis_path(): void
    {
        config(['filesystems.disks.local.driver' => 'local']);

        $this->assertSame(
            'epcis/outbound/file.xml',
            EpcisStoragePath::onDisk('local', 'epcis/outbound/file.xml', 'tenant-1'),
        );
    }

    #[Test]
    public function s3_disk_uses_hub_inbound_key(): void
    {
        config(['filesystems.disks.epcis_inbound.driver' => 's3']);

        $this->assertSame(
            'inbound/a.xml',
            EpcisStoragePath::onDisk('epcis_inbound', 'epcis/inbound/a.xml', 'tenant-1'),
        );
    }

    #[Test]
    public function s3_disk_passes_through_legacy_stored_inbound_tenant_key(): void
    {
        config(['filesystems.disks.epcis_inbound.driver' => 's3']);

        $legacy = 'tenants/tenant-1/epcis/inbound/a.xml';

        $this->assertSame(
            $legacy,
            EpcisStoragePath::onDisk('epcis_inbound', $legacy, 'tenant-1'),
        );
    }

    #[Test]
    public function s3_disk_does_not_rewrite_already_hub_key(): void
    {
        config(['filesystems.disks.epcis_inbound.driver' => 's3']);

        $this->assertSame(
            'inbound/a.xml',
            EpcisStoragePath::onDisk('epcis_inbound', 'inbound/a.xml', 'tenant-1'),
        );
    }

    #[Test]
    public function s3_disk_passes_through_legacy_stored_tenant_key(): void
    {
        config(['filesystems.disks.epcis_inbound.driver' => 's3']);

        $legacy = 'tenants/tenant-1/epcis/outbound/ship.xml';

        $this->assertSame(
            $legacy,
            EpcisStoragePath::onDisk('epcis_inbound', $legacy, 'tenant-1'),
        );
    }

    #[Test]
    public function s3_disk_rejects_new_outbound_paths(): void
    {
        config(['filesystems.disks.epcis_inbound.driver' => 's3']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Outbound EPCIS payloads must use the local disk');

        EpcisStoragePath::onDisk('epcis_inbound', 'epcis/outbound/file.xml', 'tenant-1');
    }
}
