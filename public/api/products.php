<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/src/env.php';

use App\InvoiceNinjaService;

header('Content-Type: application/json; charset=utf-8');

try {
    bootstrapApp();

    $search = isset($_GET['q']) ? trim((string) $_GET['q']) : null;
    if ($search === '') {
        $search = null;
    }

    $service = new InvoiceNinjaService(
        env('INVOICE_NINJA_API_TOKEN'),
        env('INVOICE_NINJA_URL', 'https://invoiceninja-dgw3dum9w4080ayfserza7pe.ahmedalmousawi.ir'),
    );

    $products = $service->listProducts($search);

    $payload = array_map(static function (array $product): array {
        return [
            'id' => $product['id'] ?? null,
            'product_key' => $product['product_key'] ?? '',
            'notes' => $product['notes'] ?? '',
            'price' => $product['price'] ?? 0,
            'cost' => $product['cost'] ?? 0,
        ];
    }, $products);

    echo json_encode(['data' => $payload], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
