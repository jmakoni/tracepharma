<?php

namespace Tests\Unit\Support\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Support\Epcis\PersistEpcisXmlPayload;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PersistEpcisXmlPayloadTest extends TestCase
{
    #[Test]
    public function rejects_silent_put_false_and_does_not_record_sha(): void
    {
        $disk = $this->mock(Filesystem::class);
        $disk->shouldReceive('put')->twice()->andReturn(false);
        $disk->shouldReceive('exists')->never();

        Storage::shouldReceive('disk')->with('blocked_local')->andReturn($disk);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $document = new class extends EpcisDocument
        {
            public bool $wasSaved = false;

            public function save(array $options = []): bool
            {
                $this->wasSaved = true;

                return true;
            }
        };
        $document->forceFill(['id' => 999001]);
        $document->exists = true;

        try {
            app(PersistEpcisXmlPayload::class)->handle(
                $document,
                '<epcis/>',
                'epcis/outbound/silent-fail.xml',
                'blocked_local',
                'Test EPCIS',
            );
            $this->fail('Expected RuntimeException when put returns false.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unable to persist Test EPCIS payload', $e->getMessage());
            $this->assertFalse($document->wasSaved);
            $this->assertNull($document->file_sha256);
        }
    }

    #[Test]
    public function stores_on_local_fallback_when_preferred_disk_fails(): void
    {
        $okRoot = sys_get_temp_dir().'/tp-persist-ok-'.uniqid('', true);
        mkdir($okRoot, 0755, true);

        $blockedFile = sys_get_temp_dir().'/tp-persist-fail-'.uniqid('', true);
        file_put_contents($blockedFile, 'not-a-directory');

        config([
            'filesystems.disks.preferred_failing_disk' => [
                'driver' => 'local',
                'root' => $blockedFile,
                'throw' => true,
            ],
            'filesystems.disks.local' => [
                'driver' => 'local',
                'root' => $okRoot,
                'throw' => false,
            ],
        ]);
        Storage::forgetDisk('preferred_failing_disk');
        Storage::forgetDisk('local');

        $document = new class extends EpcisDocument
        {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $document->forceFill(['id' => 999002]);
        $document->exists = true;

        try {
            app(PersistEpcisXmlPayload::class)->handle(
                $document,
                '<epcis>ok</epcis>',
                'epcis/outbound/fallback-ok.xml',
                'preferred_failing_disk',
                'Test EPCIS',
            );

            $this->assertSame('local', $document->payload_disk);
            $this->assertSame('epcis/outbound/fallback-ok.xml', $document->payload_path);
            $this->assertSame(hash('sha256', '<epcis>ok</epcis>'), $document->file_sha256);
            $this->assertTrue(Storage::disk('local')->exists('epcis/outbound/fallback-ok.xml'));
        } finally {
            Storage::disk('local')->delete('epcis/outbound/fallback-ok.xml');
            @unlink($blockedFile);
            @rmdir($okRoot.'/epcis/outbound');
            @rmdir($okRoot.'/epcis');
            @rmdir($okRoot);
        }
    }
}
