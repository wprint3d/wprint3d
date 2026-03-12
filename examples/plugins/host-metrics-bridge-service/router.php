<?php

require_once __DIR__.'/../../../vendor/autoload.php';

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/health' && $method === 'GET') {
    echo json_encode([
        'ok' => true,
        'service' => 'host-metrics-bridge',
    ], JSON_UNESCAPED_SLASHES);

    return;
}

if ($path === '/actions/host_metrics' && $method === 'POST') {
    $reader = new \App\Plugins\Support\HostMetricsReader();

    echo json_encode([
        'data' => $reader->fromHost(),
    ], JSON_UNESCAPED_SLASHES);

    return;
}

http_response_code(404);

echo json_encode([
    'message' => 'Not found.',
], JSON_UNESCAPED_SLASHES);
