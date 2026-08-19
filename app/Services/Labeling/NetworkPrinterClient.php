<?php

namespace App\Services\Labeling;

class NetworkPrinterClient
{
    public function send(string $host, int $port, string $payload, int $timeoutSeconds = 5): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);

        if ($socket === false) {
            throw new \RuntimeException("Unable to connect to printer at {$host}:{$port} — {$errstr} ({$errno})");
        }

        try {
            $written = fwrite($socket, $payload);

            if ($written === false || $written < strlen($payload)) {
                throw new \RuntimeException("Incomplete write to printer at {$host}:{$port}.");
            }
        } finally {
            fclose($socket);
        }
    }
}
