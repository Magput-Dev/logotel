<?php

declare(strict_types=1);

$dir = getenv('OTLP_CAPTURE_DIR') ?: sys_get_temp_dir();
$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$safe = str_replace(['/', '\\'], '_', trim($path, '/'));
if ($safe === '') {
    $safe = 'root';
}

$payload = file_get_contents('php://input') ?: '';
file_put_contents($dir . DIRECTORY_SEPARATOR . 'otlp-' . $safe . '.bin', $payload);
file_put_contents($dir . DIRECTORY_SEPARATOR . 'otlp-' . $safe . '.meta', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'content_type' => $_SERVER['HTTP_CONTENT_TYPE'] ?? ($_SERVER['CONTENT_TYPE'] ?? ''),
    'length' => strlen($payload),
], JSON_THROW_ON_ERROR));

header('Content-Type: application/json');
http_response_code(200);
echo '{}';
