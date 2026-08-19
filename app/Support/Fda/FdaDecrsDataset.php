<?php

namespace App\Support\Fda;

use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Resolves the FDA DECRS zip (or a local txt/zip) and streams drls_reg.txt.
 */
class FdaDecrsDataset
{
    public const DEFAULT_URL = 'https://www.accessdata.fda.gov/cder/drls_reg.zip';

    public const TXT_MEMBER = 'drls_reg.txt';

    public function resolvePath(?string $path, bool $freshDownload, string $storageRelative = 'fda/decrs'): string
    {
        if ($path !== null && $path !== '') {
            if (! File::exists($path)) {
                throw new RuntimeException("FDA DECRS file not found: {$path}");
            }

            if (str_ends_with(strtolower($path), '.txt')) {
                $this->validateTxt($path);

                return $path;
            }

            if (str_ends_with(strtolower($path), '.zip')) {
                return $this->extractTxtFromZip($path, storage_path('app/'.trim($storageRelative, '/')));
            }

            throw new RuntimeException("Unsupported DECRS path (expected .txt or .zip): {$path}");
        }

        $storageDir = storage_path('app/'.trim($storageRelative, '/'));
        File::ensureDirectoryExists($storageDir);

        $cachedZip = $this->latestCachedZip($storageDir);

        if ($freshDownload || $cachedZip === null) {
            $cachedZip = $this->downloadToDatedCache($storageDir);
        }

        return $this->extractTxtFromZip($cachedZip, $storageDir);
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    public function eachRow(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open FDA DECRS file: {$path}");
        }

        try {
            $headerLine = fgets($handle);

            if ($headerLine === false) {
                return;
            }

            $headers = array_map(
                static fn (string $header): string => trim($header, " \t\r\n"),
                explode("\t", $headerLine)
            );

            if (($headers[0] ?? '') === '') {
                array_shift($headers);
            }

            while (($line = fgets($handle)) !== false) {
                if (trim($line, " \t\r\n") === '') {
                    continue;
                }

                $values = explode("\t", $line);
                $row = [];

                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }

                    $row[$header] = self::toUtf8(trim($values[$index] ?? '', " \t\r\n"));
                }

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    private function downloadToDatedCache(string $storageDir): string
    {
        $tmp = $storageDir.'/decrs-download.tmp';
        $url = (string) config('fda.decrs_url', self::DEFAULT_URL);

        if (File::exists($tmp)) {
            File::delete($tmp);
        }

        $response = Http::timeout(120)
            ->withHeaders([
                'User-Agent' => FdaDownloadHeaders::USER_AGENT,
                'Accept' => 'application/zip,*/*',
            ])
            ->sink($tmp)
            ->get($url);

        if ($response->failed()) {
            if (File::exists($tmp)) {
                File::delete($tmp);
            }

            throw new RuntimeException("Failed to download FDA DECRS dataset from {$url} (HTTP {$response->status()})");
        }

        try {
            $this->assertZipMagic($tmp);
        } catch (RuntimeException $exception) {
            if (File::exists($tmp)) {
                File::delete($tmp);
            }

            throw $exception;
        }

        $date = $this->cacheDateFromResponse($response->header('Last-Modified'));
        $destination = $storageDir.'/decrs-'.$date.'.zip';

        if (File::exists($destination)) {
            File::delete($destination);
        }

        File::move($tmp, $destination);

        return $destination;
    }

    private function cacheDateFromResponse(mixed $lastModified): string
    {
        if (is_string($lastModified) && $lastModified !== '') {
            try {
                return Carbon::parse($lastModified)->format('Y-m-d');
            } catch (\Throwable) {
                // fall through
            }
        }

        return now()->format('Y-m-d');
    }

    private function latestCachedZip(string $storageDir): ?string
    {
        $files = glob($storageDir.'/decrs-*.zip') ?: [];

        if ($files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    private function extractTxtFromZip(string $zipPath, string $destinationDir): string
    {
        $this->assertZipMagic($zipPath);

        File::ensureDirectoryExists($destinationDir);

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException("Unable to open DECRS zip: {$zipPath}");
        }

        try {
            $txtIndex = $zip->locateName(self::TXT_MEMBER, ZipArchive::FL_NOCASE);

            if ($txtIndex === false) {
                throw new RuntimeException(
                    "DECRS zip at {$zipPath} does not contain ".self::TXT_MEMBER.'. '.
                    'FDA often returns an HTML apology page when rate-limited — download the file manually and pass --path=.'
                );
            }

            $extracted = $destinationDir.'/'.self::TXT_MEMBER;
            $stream = $zip->getStream($zip->getNameIndex($txtIndex));

            if ($stream === false) {
                throw new RuntimeException("Unable to read ".self::TXT_MEMBER." from {$zipPath}");
            }

            $out = fopen($extracted, 'wb');

            if ($out === false) {
                fclose($stream);
                throw new RuntimeException("Unable to write {$extracted}");
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
        } finally {
            $zip->close();
        }

        $this->validateTxt($extracted);

        return $extracted;
    }

    public static function toUtf8(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = str_replace("\xAD", '', $value);
            $converted = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            $value = is_string($converted) ? $converted : $value;
        }

        return str_replace("\u{00AD}", '', $value);
    }

    private function assertZipMagic(string $path): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open {$path}");
        }

        $magic = fread($handle, 4);
        fclose($handle);

        if ($magic !== "PK\x03\x04") {
            throw new RuntimeException(
                "FDA DECRS file at {$path} is not a zip archive. ".
                'FDA often returns an HTML apology/404 page when rate-limited — download the file manually and pass --path=, or retry with --fresh-download later.'
            );
        }
    }

    private function validateTxt(string $path): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open FDA DECRS file: {$path}");
        }

        $firstLine = fgets($handle);
        fclose($handle);

        $firstLine = $firstLine === false ? '' : $firstLine;
        $header = trim($firstLine, " \t\r\n");

        if (! str_contains($header, "\t") || ! str_contains($header, 'FEI_NUMBER')) {
            throw new RuntimeException(
                "FDA DECRS file at {$path} does not look like drls_reg.txt. ".
                'Expected a tab-delimited header containing FEI_NUMBER.'
            );
        }
    }
}
