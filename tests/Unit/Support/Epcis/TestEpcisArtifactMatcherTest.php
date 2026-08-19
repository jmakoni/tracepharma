<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\TestEpcisArtifactMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TestEpcisArtifactMatcherTest extends TestCase
{
    private TestEpcisArtifactMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new TestEpcisArtifactMatcher;
    }

    #[Test]
    public function matches_exact_webhook_test_filename(): void
    {
        $this->assertTrue($this->matcher->isTestDocumentFilename('webhook-test.xml'));
        $this->assertTrue($this->matcher->isTestDocumentFilename('WEBHOOK-TEST.XML'));
    }

    #[Test]
    public function matches_generated_inbound_timestamp_filenames(): void
    {
        $this->assertTrue($this->matcher->isTestDocumentFilename('inbound-20260807234810.xml'));
        $this->assertTrue($this->matcher->isTestDocumentFilename('inbound-20260101000000.xml'));
    }

    #[Test]
    public function rejects_inbound_filenames_with_wrong_digit_count(): void
    {
        $this->assertFalse($this->matcher->isTestDocumentFilename('inbound-2026080723481.xml'));
        $this->assertFalse($this->matcher->isTestDocumentFilename('inbound-202608072348101.xml'));
        $this->assertFalse($this->matcher->isTestDocumentFilename('inbound-abcdefghijklmn.xml'));
    }

    #[Test]
    public function matches_resource_test_filenames_for_either_direction(): void
    {
        $this->assertTrue($this->matcher->isTestDocumentFilename('inbound-resource-test-Ab3dF9.xml'));
        $this->assertTrue($this->matcher->isTestDocumentFilename('outbound-resource-test-Zz9kL2.xml'));
    }

    #[Test]
    public function does_not_match_real_partner_style_filenames(): void
    {
        $this->assertFalse($this->matcher->isTestDocumentFilename(
            'ou_xttrium_prod_dc_systechcitadel_dc_com_2026_07_15_21_35_0984bd24-8095-11f1-9eb2-0242ac110003-processed_data.xml',
        ));
        $this->assertFalse($this->matcher->isTestDocumentFilename('abc-epcis-1_2-sample_nov22.xml'));
        $this->assertFalse($this->matcher->isTestDocumentFilename('ship22-full-history-validate.xml'));
        $this->assertFalse($this->matcher->isTestDocumentFilename('minimal_object_shipping.xml'));
    }

    #[Test]
    public function rejects_blank_filename(): void
    {
        $this->assertFalse($this->matcher->isTestDocumentFilename(''));
        $this->assertFalse($this->matcher->isTestDocumentFilename('   '));
    }

    #[Test]
    public function matches_known_test_connection_names(): void
    {
        $this->assertTrue($this->matcher->isTestInboundConnectionName('Webhook Test'));
        $this->assertTrue($this->matcher->isTestInboundConnectionName('webhook test'));
        $this->assertTrue($this->matcher->isTestInboundConnectionName('Cardinal HTTPS'));
        $this->assertTrue($this->matcher->isTestInboundConnectionName('Systech Hub Test'));
        $this->assertTrue($this->matcher->isTestInboundConnectionName('Some Hub Test Connection'));
        $this->assertTrue($this->matcher->isTestInboundConnectionName('Another Webhook Test 2'));
    }

    #[Test]
    public function does_not_match_real_partner_connection_names(): void
    {
        $this->assertFalse($this->matcher->isTestInboundConnectionName('McKesson SFTP'));
        $this->assertFalse($this->matcher->isTestInboundConnectionName('Cencora Hub'));
        $this->assertFalse($this->matcher->isTestInboundConnectionName('AmerisourceBergen'));
    }

    #[Test]
    public function rejects_blank_connection_name(): void
    {
        $this->assertFalse($this->matcher->isTestInboundConnectionName(''));
        $this->assertFalse($this->matcher->isTestInboundConnectionName('  '));
    }
}
