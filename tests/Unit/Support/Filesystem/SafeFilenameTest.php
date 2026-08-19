<?php

namespace Tests\Unit\Support\Filesystem;

use App\Support\Filesystem\SafeFilename;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SafeFilenameTest extends TestCase
{
    #[Test]
    public function for_upload_strips_path_and_control_characters(): void
    {
        $filename = SafeFilename::forUpload(
            "../../etc/\x7Fpasswd\x01.xml",
            'fallback.xml',
        );

        $this->assertSame('passwd.xml', $filename);
    }

    #[Test]
    public function for_upload_appends_xml_extension_when_missing(): void
    {
        $filename = SafeFilename::forUpload('ship-file', 'fallback.xml');

        $this->assertSame('ship-file.xml', $filename);
    }

    #[Test]
    public function for_download_sanitizes_original_filename(): void
    {
        $filename = SafeFilename::forDownload(
            "../nested/\x01evil\x7Fname.xml",
            'storage/epcis/outbound/ignored.xml',
        );

        $this->assertSame('evilname.xml', $filename);
    }

    #[Test]
    public function for_download_falls_back_to_payload_basename(): void
    {
        $filename = SafeFilename::forDownload(
            null,
            'epcis/outbound/payload-'.chr(1).'file.xml',
        );

        $this->assertSame('payload-file.xml', $filename);
    }
}
