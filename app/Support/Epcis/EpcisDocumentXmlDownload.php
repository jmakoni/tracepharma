<?php

namespace App\Support\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Support\Filesystem\SafeFilename;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream an EPCIS document's stored XML payload for download.
 */
final class EpcisDocumentXmlDownload
{
    public static function available(EpcisDocument $document): bool
    {
        $path = $document->payload_path;

        if (! filled($path)) {
            return false;
        }

        return Storage::disk($document->payloadFilesystemDisk())->exists((string) $path);
    }

    public static function filename(EpcisDocument $document): string
    {
        return SafeFilename::forDownload(
            filled($document->original_filename) ? (string) $document->original_filename : null,
            filled($document->payload_path) ? (string) $document->payload_path : null,
        );
    }

    public static function response(EpcisDocument $document): StreamedResponse
    {
        $path = (string) $document->payload_path;

        return Storage::disk($document->payloadFilesystemDisk())->download(
            $path,
            self::filename($document),
            ['Content-Type' => 'application/xml; charset=UTF-8'],
        );
    }
}
