<?php

/**
 * Minimal PMS → TracePharma dispense-check bridge (reference only).
 *
 * Deploy behind HTTPS on your pharmacy network. Set env:
 *   TP_BASE_URL=https://your-tenant.example.com
 *   TP_API_TOKEN=your-sanctum-token-with-vrs-dispense-check
 *
 * POST JSON from your PMS middleware:
 *   {"gtin":"00301123456789","serial":"ABC123","rx_number":"RX-1001"}
 */

declare(strict_types=1);

header('Content-Type: application/json');

$baseUrl = rtrim(getenv('TP_BASE_URL') ?: '', '/');
$token = getenv('TP_API_TOKEN') ?: '';

if ($baseUrl === '' || $token === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Configure TP_BASE_URL and TP_API_TOKEN']);

    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (! is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);

    exit;
}

$payload = array_filter([
    'gtin' => $input['gtin'] ?? null,
    'serial' => $input['serial'] ?? null,
    'barcode' => $input['barcode'] ?? null,
]);

$ch = curl_init($baseUrl.'/api/v1/dispense-check');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer '.$token,
        'Accept: application/json',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);

$responseBody = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($status > 0 ? $status : 502);
echo $responseBody !== false ? $responseBody : json_encode(['error' => 'Upstream request failed']);
