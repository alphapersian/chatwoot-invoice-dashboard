<?php

/**
 * Minimal SDK usage — create + list invoices by phone.
 *
 * Set INVOICE_NINJA_API_TOKEN in .env first, then:
 *   php example.php
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\InvoiceNinjaService;
use InvoiceNinja\Sdk\InvoiceNinja;

// --- Low-level SDK (same as official README) ---
$ninja = new InvoiceNinja(getenv('INVOICE_NINJA_API_TOKEN') ?: 'YOUR_TOKEN');
$ninja->setUrl(rtrim(getenv('INVOICE_NINJA_URL') ?: 'https://invoiceninja-dgw3dum9w4080ayfserza7pe.ahmedalmousawi.ir', '/'));

// List invoices for a client id (from GitHub: invoiceninja/sdk-php#105)
// $invoices = $ninja->invoices->all(['client_id' => 'CLIENT_HASHED_ID']);

// Create invoice
// $invoice = $ninja->invoices->create([
//     'client_id' => 'CLIENT_HASHED_ID',
//     'date' => date('Y-m-d'),
//     'line_items' => [
//         ['product_key' => 'service', 'notes' => 'Consulting', 'quantity' => 1, 'cost' => 150],
//     ],
// ]);

// --- Service wrapper (phone → client → invoices) ---
$phone = '+1234567890';
$service = new InvoiceNinjaService(
    getenv('INVOICE_NINJA_API_TOKEN') ?: 'YOUR_TOKEN',
    getenv('INVOICE_NINJA_URL') ?: 'https://invoiceninja-dgw3dum9w4080ayfserza7pe.ahmedalmousawi.ir',
);

$existing = $service->listInvoicesByPhone($phone);
$created = $service->createInvoiceForPhone($phone, [
    ['product_key' => 'service', 'notes' => 'Monthly subscription', 'quantity' => 1, 'cost' => 99],
]);

echo "Existing invoices: " . count($existing) . PHP_EOL;
echo "Created invoice: " . ($created['number'] ?? $created['id'] ?? 'unknown') . PHP_EOL;
