<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$token = getenv('INVOICE_NINJA_API_TOKEN') ?: ($_ENV['INVOICE_NINJA_API_TOKEN'] ?? '');
$url = getenv('INVOICE_NINJA_URL') ?: ($_ENV['INVOICE_NINJA_URL'] ?? '');

echo json_encode([
    'status' => 'ok',
    'timestamp' => gmdate('c'),
    'configured' => $token !== '' && $url !== '',
], JSON_UNESCAPED_UNICODE);
