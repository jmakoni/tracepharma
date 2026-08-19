<?php

namespace App\Services\Epcis\Outbound;

use RuntimeException;

/**
 * Lean AS2 S/MIME CMS envelope — sign and/or encrypt outbound payloads via OpenSSL tmpfile().
 *
 * Partner-specific MIME quirks (Content-Disposition, MIC algorithms) may still need tuning.
 */
final class As2SmimeEnvelope
{
    public function envelope(
        string $payload,
        ?string $signingCertPem,
        ?string $signingKeyPem,
        ?string $partnerEncryptCertPem,
    ): As2SmimeEnvelopeResult {
        $canSign = filled($signingCertPem) && filled($signingKeyPem);
        $canEncrypt = filled($partnerEncryptCertPem);

        if (! $canSign && ! $canEncrypt) {
            return new As2SmimeEnvelopeResult(
                body: $payload,
                contentType: 'application/xml',
                smimeApplied: false,
            );
        }

        $body = $payload;
        $contentType = 'application/xml';

        if ($canSign) {
            [$body, $contentType] = $this->sign($payload, $signingCertPem, $signingKeyPem);
        }

        if ($canEncrypt) {
            [$body, $contentType] = $this->encrypt($body, $partnerEncryptCertPem);
        }

        return new As2SmimeEnvelopeResult(
            body: $body,
            contentType: $contentType,
            smimeApplied: true,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sign(string $payload, string $signingCertPem, string $signingKeyPem): array
    {
        $inputFile = tmpfile();
        $outputFile = tmpfile();
        $certFile = $this->writePemToTempFile($signingCertPem);
        $keyFile = $this->writePemToTempFile($signingKeyPem);

        if ($inputFile === false || $outputFile === false) {
            throw new RuntimeException('Failed to create temporary file for AS2 signing.');
        }

        try {
            fwrite($inputFile, $payload);
            fflush($inputFile);

            $certPath = $this->fileUri($certFile);
            $keyPath = $this->fileUri($keyFile);

            if (! @openssl_pkcs7_sign(
                stream_get_meta_data($inputFile)['uri'],
                stream_get_meta_data($outputFile)['uri'],
                $certPath,
                $keyPath,
                [],
                PKCS7_BINARY,
            )) {
                throw new RuntimeException('Failed to sign AS2 payload: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
            }

            return $this->parseMimeOutput($outputFile);
        } finally {
            fclose($inputFile);
            fclose($outputFile);
            fclose($certFile);
            fclose($keyFile);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function encrypt(string $payload, string $partnerEncryptCertPem): array
    {
        $inputFile = tmpfile();
        $outputFile = tmpfile();
        $certFile = $this->writePemToTempFile($partnerEncryptCertPem);

        if ($inputFile === false || $outputFile === false) {
            throw new RuntimeException('Failed to create temporary file for AS2 encryption.');
        }

        try {
            fwrite($inputFile, $payload);
            fflush($inputFile);

            $certPath = $this->fileUri($certFile);

            if (! @openssl_pkcs7_encrypt(
                stream_get_meta_data($inputFile)['uri'],
                stream_get_meta_data($outputFile)['uri'],
                $certPath,
                [],
                0,
                OPENSSL_CIPHER_AES_256_CBC,
            )) {
                throw new RuntimeException('Failed to encrypt AS2 payload: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
            }

            [$body, $contentType] = $this->parseMimeOutput($outputFile);

            if (! str_contains(strtolower($contentType), 'pkcs7-mime')) {
                $contentType = 'application/pkcs7-mime; smime-type=enveloped-data';
            }

            return [$body, $contentType];
        } finally {
            fclose($inputFile);
            fclose($outputFile);
            fclose($certFile);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseMimeOutput($stream): array
    {
        rewind($stream);
        $raw = stream_get_contents($stream);

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('OpenSSL S/MIME output was empty.');
        }

        if (! preg_match('/(\r\n\r\n|\n\n)/', $raw, $match, PREG_OFFSET_CAPTURE)) {
            return [$raw, 'application/octet-stream'];
        }

        $separatorLength = strlen($match[0][0]);
        $headerEnd = $match[0][1];
        $headerBlock = substr($raw, 0, $headerEnd);
        $body = substr($raw, $headerEnd + $separatorLength);
        $contentType = 'application/octet-stream';

        foreach (explode("\n", str_replace("\r\n", "\n", $headerBlock)) as $line) {
            if (stripos($line, 'content-type:') === 0) {
                $contentType = trim(substr($line, strlen('content-type:')));

                break;
            }
        }

        return [$body, $contentType];
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
