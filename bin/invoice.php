#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/env.php';

use App\InvoiceNinjaService;

function usage(): void
{
    $script = basename(__FILE__);
    fwrite(STDERR, <<<TXT
Invoice Ninja CLI (PHP SDK)

Usage:
  php bin/{$script} list --phone="+1234567890"
  php bin/{$script} create --phone="+1234567890" [--amount=100] [--description="Service"]

Environment (.env):
  INVOICE_NINJA_URL       Base URL (no trailing slash required)
  INVOICE_NINJA_API_TOKEN API token from Invoice Ninja settings

TXT);
}

function printJson(mixed $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

bootstrapApp();

$command = $argv[1] ?? null;
if ($command === null || in_array($command, ['-h', '--help', 'help'], true)) {
    usage();
    exit($command === null ? 1 : 0);
}

$options = getopt('', ['phone:', 'amount::', 'description::', 'client-name::', 'email::']);
$phone = $options['phone'] ?? null;

if ($phone === null || $phone === '') {
    fwrite(STDERR, "Error: --phone is required.\n\n");
    usage();
    exit(1);
}

try {
    $service = new InvoiceNinjaService(
        env('INVOICE_NINJA_API_TOKEN'),
        env('INVOICE_NINJA_URL', 'https://invoiceninja-dgw3dum9w4080ayfserza7pe.ahmedalmousawi.ir'),
    );

    if ($command === 'list') {
        $invoices = $service->listInvoicesByPhone($phone);

        $summary = array_map(static function (array $invoice): array {
            return [
                'id' => $invoice['id'] ?? null,
                'number' => $invoice['number'] ?? null,
                'client_id' => $invoice['client_id'] ?? null,
                'amount' => $invoice['amount'] ?? null,
                'balance' => $invoice['balance'] ?? null,
                'status_id' => $invoice['status_id'] ?? null,
                'date' => $invoice['date'] ?? null,
                'due_date' => $invoice['due_date'] ?? null,
            ];
        }, $invoices);

        printJson([
            'phone' => $phone,
            'count' => count($summary),
            'invoices' => $summary,
        ]);
        exit(0);
    }

    if ($command === 'create') {
        $amount = (float) ($options['amount'] ?? 100);
        $description = $options['description'] ?? 'Invoice line item';

        $invoice = $service->createInvoiceForPhone(
            $phone,
            [
                [
                    'product_key' => 'service',
                    'notes' => $description,
                    'quantity' => 1,
                    'cost' => $amount,
                ],
            ],
            $options['client-name'] ?? null,
            $options['email'] ?? null,
        );

        printJson([
            'phone' => $phone,
            'created' => true,
            'invoice' => [
                'id' => $invoice['id'] ?? null,
                'number' => $invoice['number'] ?? null,
                'client_id' => $invoice['client_id'] ?? null,
                'amount' => $invoice['amount'] ?? null,
                'balance' => $invoice['balance'] ?? null,
                'date' => $invoice['date'] ?? null,
                'due_date' => $invoice['due_date'] ?? null,
            ],
        ]);
        exit(0);
    }

    fwrite(STDERR, "Unknown command: {$command}\n\n");
    usage();
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
