<?php

namespace App\Support\OpenFda;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Resolves and loads the openFDA NDC directory JSON dataset, whether it
 * comes from a local file, a local zip, or a fresh download.
 */
class OpenFdaNdcDataset
{
    public const DEFAULT_URL = 'https://download.open.fda.gov/drug/ndc/drug-ndc-0001-of-0001.json.zip';

    public function resolveJsonPath(?string $path, bool $freshDownload, string $storageRelative = 'openfda'): string
    {
        if ($path !== null && $path !== '') {
            if (str_ends_with(strtolower($path), '.json')) {
                if (! File::exists($path)) {
                    throw new RuntimeException("openFDA JSON file not found: {$path}");
                }

                return $path;
            }

            if (str_ends_with(strtolower($path), '.zip')) {
                if (! File::exists($path)) {
                    throw new RuntimeException("openFDA zip file not found: {$path}");
                }

                return $this->extractJsonFromZip($path, storage_path('app/'.trim($storageRelative, '/')));
            }

            throw new RuntimeException("Unsupported openFDA dataset path (expected .json or .zip): {$path}");
        }

        $storageDir = storage_path('app/'.trim($storageRelative, '/'));
        File::ensureDirectoryExists($storageDir);

        $zipPath = $storageDir.'/drug-ndc.json.zip';

        return Cache::lock('openfda:ndc:download', 600)->block(600, function () use ($freshDownload, $zipPath, $storageDir): string {
            if ($freshDownload || ! File::exists($zipPath)) {
                $this->download(self::DEFAULT_URL, $zipPath);
            }

            return $this->extractJsonFromZip($zipPath, $storageDir);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadResults(string $jsonPath): array
    {
        ini_set('memory_limit', '2G');

        $contents = File::get($jsonPath);
        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! isset($decoded['results']) || ! is_array($decoded['results'])) {
            throw new RuntimeException("Invalid openFDA NDC dataset JSON: {$jsonPath}");
        }

        return $decoded['results'];
    }

    private function download(string $url, string $destination): void
    {
        File::ensureDirectoryExists(dirname($destination));

        $response = Http::timeout(300)->sink($destination)->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Failed to download openFDA dataset from {$url} (HTTP {$response->status()})");
        }
    }

    private function extractJsonFromZip(string $zipPath, string $destinationDir): string
    {
        File::ensureDirectoryExists($destinationDir);

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Unable to open openFDA zip archive: {$zipPath}");
        }

        $jsonEntryName = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if ($entry !== false && str_ends_with(strtolower($entry), '.json')) {
                $jsonEntryName = $entry;
                break;
            }
        }

        if ($jsonEntryName === null) {
            $zip->close();

            throw new RuntimeException("No JSON file found inside openFDA zip archive: {$zipPath}");
        }

        $zip->extractTo($destinationDir, $jsonEntryName);
        $zip->close();

        $extractedPath = rtrim($destinationDir, '/').'/'.$jsonEntryName;
        $targetPath = rtrim($destinationDir, '/').'/drug-ndc.json';

        if ($extractedPath !== $targetPath) {
            File::move($extractedPath, $targetPath);
        }

        return $targetPath;
    }
}
