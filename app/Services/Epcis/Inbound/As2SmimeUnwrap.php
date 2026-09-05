<?php

namespace App\Services\Epcis\Inbound;

use App\Models\InboundConnection;
use RuntimeException;

/**
 * Inverse of outbound As2SmimeEnvelope: lab XML passes through; CMS is decrypted
 * with the inbound connection's decrypt PEMs.
 */
final class As2SmimeUnwrap
{
    public function unwrap(InboundConnection $connection, string $body, ?string $contentType): string
    {
        $allowUnsigned = (bool) ($connection->settings['allow_unsigned_xml'] ?? false);

        if ($this->looksLikeXml($body)) {
            if (! $allowUnsigned) {
                throw new RuntimeException('Unsigned AS2 XML is not allowed for this connection.');
            }

            $this->assertUnsignedXmlAllowedOutsideProduction();

            return $body;
        }

        $credentials = $connection->credentials ?? [];
        $decryptCert = $credentials['decrypt_cert_pem'] ?? null;
        $decryptKey = $credentials['decrypt_key_pem'] ?? null;

        if (! filled($decryptCert) || ! filled($decryptKey)) {
            throw new RuntimeException('AS2 payload is not XML and no decrypt certificate is configured.');
        }

        $decrypted = $this->decrypt($body, $contentType, (string) $decryptCert, (string) $decryptKey);

        $partnerCert = $credentials['partner_signing_cert_pem'] ?? null;

        if ($this->looksLikeXml($decrypted)) {
            if (! $allowUnsigned) {
                throw new RuntimeException('AS2 payload is encrypted but not signed.');
            }

            $this->assertUnsignedXmlAllowedOutsideProduction();

            return $decrypted;
        }

        if (filled($partnerCert) && $this->looksLikeSmime($decrypted, null)) {
            $verified = $this->verify($decrypted, (string) $partnerCert);
            if ($this->looksLikeXml($verified)) {
                return $verified;
            }
        }

        throw new RuntimeException('AS2 S/MIME unwrap did not produce EPCIS XML.');
    }

    /**
     * Lab-only flag: unsigned XML is never permitted in production.
     */
    private function assertUnsignedXmlAllowedOutsideProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Unsigned AS2 XML is not allowed in production.');
        }
    }

    private function decrypt(string $body, ?string $contentType, string $certPem, string $keyPem): string
    {
        $inputFile = tmpfile();
        $outputFile = tmpfile();
        $certFile = $this->writePemToTempFile($certPem);
        $keyFile = $this->writePemToTempFile($keyPem);

        if ($inputFile === false || $outputFile === false) {
            throw new RuntimeException('Failed to create temporary file for AS2 decrypt.');
        }

        try {
            fwrite($inputFile, $this->wrapAsSmime($body, $contentType));
            fflush($inputFile);

            if (! @openssl_pkcs7_decrypt(
                stream_get_meta_data($inputFile)['uri'],
                stream_get_meta_data($outputFile)['uri'],
                $this->fileUri($certFile),
                $this->fileUri($keyFile),
            )) {
                throw new RuntimeException('Failed to decrypt AS2 payload: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
            }

            rewind($outputFile);
            $plain = stream_get_contents($outputFile);

            if (! is_string($plain) || $plain === '') {
                throw new RuntimeException('AS2 decrypt produced an empty payload.');
            }

            return $plain;
        } finally {
            fclose($inputFile);
            fclose($outputFile);
            fclose($certFile);
            fclose($keyFile);
        }
    }

    private function verify(string $signed, string $partnerCertPem): string
    {
        $inputFile = tmpfile();
        $contentFile = tmpfile();
        $certFile = $this->writePemToTempFile($partnerCertPem);

        if ($inputFile === false || $contentFile === false) {
            throw new RuntimeException('Failed to create temporary file for AS2 verify.');
        }

        try {
            fwrite($inputFile, $this->wrapAsSmime($signed, 'application/pkcs7-mime; smime-type=signed-data'));
            fflush($inputFile);

            $contentPath = stream_get_meta_data($contentFile)['uri'];

            $caUri = $this->fileUri($certFile);

            if (! @openssl_pkcs7_verify(
                stream_get_meta_data($inputFile)['uri'],
                0,
                null,
                [$caUri],
                $caUri,
                $contentPath,
            )) {
                throw new RuntimeException('Failed to verify AS2 signature: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
            }

            rewind($contentFile);
            $plain = stream_get_contents($contentFile);

            if (! is_string($plain) || $plain === '') {
                throw new RuntimeException('AS2 verify produced an empty payload.');
            }

            return $plain;
        } finally {
            fclose($inputFile);
            fclose($contentFile);
            fclose($certFile);
        }
    }

    private function wrapAsSmime(string $body, ?string $contentType): string
    {
        if (str_contains($body, 'Content-Type:') && preg_match('/(\r\n\r\n|\n\n)/', $body) === 1) {
            return $body;
        }

        $type = is_string($contentType) && $contentType !== ''
            ? $contentType
            : 'application/pkcs7-mime; smime-type=enveloped-data';

        $encoding = $this->looksLikeBase64($body) ? 'base64' : 'binary';

        return "MIME-Version: 1.0\r\nContent-Type: {$type}\r\nContent-Transfer-Encoding: {$encoding}\r\n\r\n".$body;
    }

    private function looksLikeXml(string $body): bool
    {
        $trimmed = ltrim($body);

        return str_starts_with($trimmed, '<?xml')
            || str_starts_with($trimmed, '<epcis:')
            || str_starts_with($trimmed, '<EPCISDocument');
    }

    private function looksLikeSmime(string $body, ?string $contentType): bool
    {
        if (is_string($contentType) && str_contains(strtolower($contentType), 'pkcs7')) {
            return true;
        }

        $lower = strtolower($body);

        return str_contains($lower, 'pkcs7-mime') || str_contains($lower, 'pkcs7-signature');
    }

    private function looksLikeBase64(string $body): bool
    {
        $compact = preg_replace('/\s+/', '', $body) ?? '';

        return $compact !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact) === 1;
    }

    private function writePemToTempFile(string $pem)
    {
        $file = tmpfile();

        if ($file === false) {
            throw new RuntimeException('Failed to create temporary PEM file.');
        }

        fwrite($file, $pem);
        fflush($file);

        return $file;
    }

    private function fileUri($file): string
    {
        $uri = stream_get_meta_data($file)['uri'] ?? null;

        if (! is_string($uri) || $uri === '') {
            throw new RuntimeException('Temporary PEM file URI is unavailable.');
        }

        return 'file://'.str_replace('\\', '/', $uri);
    }
}
