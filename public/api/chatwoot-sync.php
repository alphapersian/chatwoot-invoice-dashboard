<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/src/env.php';

use App\ChatwootContact;
use App\InvoiceNinjaService;

header('Content-Type: application/json; charset=utf-8');

try {
    bootstrapApp();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        throw new InvalidArgumentException('Invalid JSON body.');
    }

    $contactData = $body['contact'] ?? $body;
    if (!is_array($contactData)) {
        throw new InvalidArgumentException('Missing contact object.');
    }

    $contact = ChatwootContact::fromArray($contactData);
    if (!$contact->hasLookupKey()) {
        throw new InvalidArgumentException(
            'Chatwoot contact has no usable details. Add a name, phone, or email in Chatwoot.'
        );
    }

    $service = new InvoiceNinjaService(
        env('INVOICE_NINJA_API_TOKEN'),
        env('INVOICE_NINJA_URL', 'https://invoiceninja-dgw3dum9w4080ayfserza7pe.ahmedalmousawi.ir'),
    );

    $result = $service->syncChatwootContact($contact);
    $client = $result['client'];

    echo json_encode([
        'contact' => $contact->toClientPayload(),
        'client' => [
            'id' => $client['id'] ?? null,
            'name' => $client['name'] ?? $client['display_name'] ?? null,
            'phone' => $client['phone'] ?? null,
        ],
        'created' => $result['created'],
        'invoice_count' => count($result['invoices']),
        'invoices' => array_map(
            static fn (array $invoice): array => $service->summarizeInvoice($invoice),
            $result['invoices']
        ),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
