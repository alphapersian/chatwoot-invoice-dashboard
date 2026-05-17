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

    $phone = trim((string) ($_GET['phone'] ?? ''));
    $email = trim((string) ($_GET['email'] ?? ''));

    if ($phone === '' && $email === '') {
        throw new InvalidArgumentException('Provide query parameter "phone" or "email".');
    }

    $service = new InvoiceNinjaService(
        env('INVOICE_NINJA_API_TOKEN'),
        env('INVOICE_NINJA_URL', 'https://invoiceninja-dgw3dum9w4080ayfserza7pe.ahmedalmousawi.ir'),
    );

    $invoices = [];
    $client = null;

    if ($phone !== '') {
        $clients = $service->findClientsByPhone($phone);
        if (count($clients) > 1) {
            throw new RuntimeException('Multiple clients match this phone number.');
        }
        if ($clients !== []) {
            $client = $clients[0];
            $invoices = $service->listInvoicesByClientId((string) ($client['id'] ?? ''));
        }
    }

    if ($invoices === [] && $email !== '') {
        $client = $service->findClientByEmail($email);
        if ($client !== null) {
            $invoices = $service->listInvoicesByClientId((string) ($client['id'] ?? ''));
        }
    }

    echo json_encode([
        'phone' => $phone !== '' ? $phone : null,
        'email' => $email !== '' ? $email : null,
        'client' => $client ? $service->formatClientForForm($client) : null,
        'count' => count($invoices),
        'invoices' => array_map(
            static fn (array $inv): array => $service->summarizeInvoice($inv),
            $invoices
        ),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
