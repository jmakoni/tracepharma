<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\EpcisSchemaVersion;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisSchemaVersionTest extends TestCase
{
    #[Test]
    public function peek_accepts_1_2_and_1_3_only(): void
    {
        $this->assertSame('1.2', EpcisSchemaVersion::peek('schemaVersion="1.2"'));
        $this->assertSame('1.3', EpcisSchemaVersion::peek("schemaVersion='1.3'"));
        $this->assertTrue(EpcisSchemaVersion::isAccepted('1.2'));
        $this->assertTrue(EpcisSchemaVersion::isAccepted('1.3'));
        $this->assertNull(EpcisSchemaVersion::peek('schemaVersion="1.0"'));
        $this->assertNull(EpcisSchemaVersion::peek('schemaVersion="2.0"'));
        $this->assertFalse(EpcisSchemaVersion::isAccepted('1.0'));
        $this->assertFalse(EpcisSchemaVersion::isAccepted('2.0'));
    }

    #[Test]
    public function peek_file_reads_the_1_3_fixture(): void
    {
        $path = base_path('tests/Fixtures/epcis/minimal_object_shipping_1.3.xml');

        $this->assertSame('1.3', EpcisSchemaVersion::peekFile($path));
    }
}
