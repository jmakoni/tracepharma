<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Epcis;

use App\Models\Epcis\EpcisDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisDocumentPayloadMaterializeTest extends TestCase
{
    #[Test]
    public function materialize_returns_local_disk_path(): void
    {
        $relative = 'epcis/inbound/materialize-local-'.uniqid('', true).'.xml';
        $xml = '<?xml version="1.0"?><EPCISDocument/>';

        Storage::disk('local')->put($relative, $xml);

        try {
            $document = new EpcisDocument([
                'payload_disk' => 'local',
                'payload_path' => $relative,
            ]);

            $path = $document->materializePayloadPath();

            $this->assertSame(Storage::disk('local')->path($relative), $path);
            $this->assertFileExists($path);
            $this->assertSame($xml, file_get_contents($path));
            $this->assertNull($document->temporaryPayloadUrl());
        } finally {
            Storage::disk('local')->delete($relative);
        }
    }

    #[Test]
    public function materialize_streams_non_local_disk_to_temp_file(): void
    {
        $relative = 'epcis/inbound/materialize-s3.xml';
        $xml = '<?xml version="1.0"?><EPCISDocument/>';

        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, $xml);
        rewind($stream);

        $filesystem = Mockery::mock(FilesystemAdapter::class);
        $filesystem->shouldReceive('readStream')
            ->once()
            ->with($relative)
            ->andReturn($stream);

        Storage::shouldReceive('disk')
            ->once()
            ->with('epcis_inbound')
            ->andReturn($filesystem);

        config()->set('filesystems.disks.epcis_inbound.driver', 's3');

        $document = new EpcisDocument([
            'payload_disk' => 'epcis_inbound',
            'payload_path' => $relative,
        ]);

        $path = $document->materializePayloadPath();

        try {
            $tempDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
            $this->assertStringStartsWith(
                rtrim($tempDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
                realpath($path) ?: $path,
            );
            $this->assertFileExists($path);
            $this->assertSame($xml, file_get_contents($path));
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
