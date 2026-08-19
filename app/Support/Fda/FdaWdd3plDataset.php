<?php

namespace App\Support\Fda;

use Generator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolves and streams the FDA Wholesale Drug Distributor / Third-Party
 * Logistics (WDD/3PL) facilities TSV report, whether it comes from a
 * local file or a fresh download.
 */
class FdaWdd3plDataset
{
    public const DEFAULT_URL = 'https://www.accessdata.fda.gov/cder/wdd_3pl_facilities_report.txt';

    public function resolvePath(?string $path, bool $freshDownload, string $storageRelative = 'fda'): string
    {
        if ($path !== null && $path !== '') {
            if (! File::exists($path)) {
                throw new RuntimeException("FDA WDD/3PL file not found: {$path}");
            }

            $this->validate($path);

            return $path;
        }

        $storageDir = storage_path('app/'.trim($storageRelative, '/'));
        File::ensureDirectoryExists($storageDir);

        $destination = $storageDir.'/wdd_3pl_facilities_report.txt';

        if ($freshDownload || ! File::exists($destination)) {
            $this->download((string) config('fda.wdd_url', self::DEFAULT_URL), $destination);

            return $destination;
        }

        try {
            $this->validate($destination);
        } catch (RuntimeException) {
            // Stale HTML apology page from a prior blocked download — refresh once.
            $this->download((string) config('fda.wdd_url', self::DEFAULT_URL), $destination);
        }

        return $destination;
    }

    /**
     * Stream the TSV file row by row, keyed by header name.
     *
     * @return Generator<int, array<string, string>>
     */
    public function eachRow(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open FDA WDD/3PL file: {$path}");
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

                    $row[$header] = trim($values[$index] ?? '', " \t\r\n");
                }

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    private function download(string $url, string $destination): void
    {
        File::ensureDirectoryExists(dirname($destination));

        if (File::exists($destination)) {
            File::delete($destination);
        }

        $response = Http::timeout(120)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TracePharma/1.0; +https://tracepharma.test)',
                'Accept' => 'text/plain,*/*',
            ])
            ->sink($destination)
            ->get($url);

        if ($response->failed()) {
            if (File::exists($destination)) {
                File::delete($destination);
            }

            throw new RuntimeException("Failed to download FDA WDD/3PL dataset from {$url} (HTTP {$response->status()})");
        }

        try {
            $this->validate($destination);
        } catch (RuntimeException $e) {
            if (File::exists($destination)) {
                File::delete($destination);
            }

            throw $e;
        }
    }

    /**
     * Reject HTML "apology" pages (FDA's rate-limit/abuse-detection response)
     * masquerading as the TSV report.
     */
    private function validate(string $path): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open FDA WDD/3PL file: {$path}");
        }

        $firstLine = fgets($handle);
        fclose($handle);

        $firstLine = $firstLine === false ? '' : $firstLine;

        if (! str_starts_with(trim($firstLine), 'Type') || ! str_contains($firstLine, "\t")) {
            throw new RuntimeException(
                "FDA WDD/3PL file at {$path} does not look like a valid TSV export. ".
                'FDA often returns an HTML apology/404 page when rate-limited — download the file manually and pass --path=, or retry with --fresh-download later.'
            );
        }
    }
}
