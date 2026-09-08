<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\EpcisInboundStorageConfig;
use Tests\TestCase;

class EpcisInboundStorageConfigTest extends TestCase
{
    public function test_prefers_epcis_inbound_bucket_over_aws_bucket(): void
    {
        config()->set('filesystems.disks.epcis_inbound.bucket', null);
        config()->set('tracepharma.epcis.inbound_bucket', 'tracepharma-stage');
        config()->set('tracepharma.epcis.inbound_url', null);
        config()->set('filesystems.disks.s3.region', 'us-east-1');

        $this->assertSame('tracepharma-stage', EpcisInboundStorageConfig::bucket());
        $this->assertSame(
            'https://tracepharma-stage.s3.us-east-1.amazonaws.com',
            EpcisInboundStorageConfig::url(),
        );
    }

    public function test_falls_back_to_aws_bucket_when_inbound_bucket_unset(): void
    {
        config()->set('tracepharma.epcis.inbound_bucket', null);
        config()->set('tracepharma.epcis.inbound_url', null);

        putenv('EPCIS_INBOUND_BUCKET');
        unset($_ENV['EPCIS_INBOUND_BUCKET']);
        putenv('AWS_BUCKET=tracepharma-prod');
        $_ENV['AWS_BUCKET'] = 'tracepharma-prod';

        $this->assertSame('tracepharma-prod', EpcisInboundStorageConfig::bucket());
    }
}
