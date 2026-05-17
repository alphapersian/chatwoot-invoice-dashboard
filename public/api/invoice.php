<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/src/env.php';

use App\InvoiceNinjaService;

header('Content-Type: application/json; charset=utf-8');

try {
    bootstrapApp();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $invoiceId = trim((string) ($_GET['id'] ?? ''));
    if ($invoiceId === '') {
        throw new InvalidArgumentException('Query parameter "id" is required.');
    }

    $service = new InvoiceNinjaService(
        env('INVOICE_NINJA_API_TOKEN'),
        env('INVOICE_NINJA_URL', 'https://invoiceninja-dgw3dum9w4080ayfserza7pe.ahmedalmousawi.ir'),
    );

    echo json_encode(['data' => $service->getInvoice($invoiceId)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
