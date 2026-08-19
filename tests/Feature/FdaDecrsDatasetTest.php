<?php

namespace Tests\Feature;

use App\Support\Fda\FdaDecrsDataset;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class FdaDecrsDatasetTest extends TestCase
{
    #[Test]
    public function html_apology_zip_is_rejected(): void
    {
        $path = storage_path('app/fda/decrs/apology-test.zip');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "<!DOCTYPE html>\n<html><body>apology</body></html>");

        try {
            app(FdaDecrsDataset::class)->resolvePath($path, false);
            $this->fail('HTML apology was accepted as a DECRS zip.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('is not a zip archive', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function chrome_like_html_download_is_rejected(): void
    {
        Http::fake([
            'https://www.accessdata.fda.gov/cder/drls_reg.zip' => Http::response(
                '<!DOCTYPE html><html lang="en"><body>apology</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a zip archive');

        app(FdaDecrsDataset::class)->resolvePath(null, true, 'fda/decrs-test-download');
    }

    #[Test]
    public function each_row_converts_windows_1252_names_to_utf8(): void
    {
        $path = storage_path('app/fda/decrs/latin1-sample.txt');
        @mkdir(dirname($path), 0777, true);
        $latin1Name = 'Esther V'.chr(0xE1).'zquez';
        $header = "FEI_NUMBER\tREGISTRANT_NAME\n";
        $line = "0000001\t".$latin1Name."\n";
        file_put_contents($path, $header.$line);

        try {
            $rows = iterator_to_array(app(FdaDecrsDataset::class)->eachRow($path));
            $this->assertSame('Esther Vázquez', $rows[0]['REGISTRANT_NAME']);
            $this->assertTrue(mb_check_encoding($rows[0]['REGISTRANT_NAME'], 'UTF-8'));
            $this->assertSame(
                'Paulínia/SP',
                FdaDecrsDataset::toUtf8('Paul'.chr(0xED).'nia/SP')
            );
        } finally {
            @unlink($path);
        }
    }
}
