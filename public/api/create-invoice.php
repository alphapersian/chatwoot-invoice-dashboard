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

    $contactData = $body['contact'] ?? null;
    if (!is_array($contactData)) {
        throw new InvalidArgumentException('Missing contact object.');
    }

    $items = $body['items'] ?? [];
    if (!is_array($items)) {
        throw new InvalidArgumentException('Items must be an array.');
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

    $sync = $service->syncChatwootContact($contact);
    $clientId = (string) ($sync['client']['id'] ?? '');
    $lineItems = $service->resolveLineItems($items);

    $invoice = $service->createInvoiceForClient($clientId, $lineItems);
    $invoices = $service->listInvoicesByClientId($clientId);

    echo json_encode([
        'invoice' => $service->summarizeInvoice($invoice),
        'invoices' => array_map(
            static fn (array $inv): array => $service->summarizeInvoice($inv),
            $invoices
        ),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
