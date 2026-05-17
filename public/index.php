<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/env.php';

use App\InvoiceNinjaService;

bootstrapApp();

$error = null;
$success = null;
$createdInvoice = null;
$recentInvoices = [];

$defaultItems = [
    ['product_name' => '', 'notes' => '', 'quantity' => '1', 'cost' => ''],
];

$input = [
    'phone' => '',
    'client_name' => '',
    'email' => '',
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'items' => $defaultItems,
];

function makeService(): InvoiceNinjaService
{
    return new InvoiceNinjaService(
        env('INVOICE_NINJA_API_TOKEN'),
        env('INVOICE_NINJA_URL', 'https://invoiceninja-dgw3dum9w4080ayfserza7pe.ahmedalmousawi.ir'),
    );
}

/**
 * @param mixed $raw
 * @return list<array<string, string>>
 */
function parseFormItems(mixed $raw): array
{
    $fallback = [['product_name' => '', 'notes' => '', 'quantity' => '1', 'cost' => '']];

    if (!is_array($raw)) {
        return $fallback;
    }

    $items = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = [
            'product_name' => trim((string) ($row['product_name'] ?? '')),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'quantity' => trim((string) ($row['quantity'] ?? '1')),
            'cost' => trim((string) ($row['cost'] ?? '')),
        ];
    }

    return $items !== [] ? $items : $fallback;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = [
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'client_name' => trim((string) ($_POST['client_name'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'invoice_date' => trim((string) ($_POST['invoice_date'] ?? '')),
        'due_date' => trim((string) ($_POST['due_date'] ?? '')),
        'items' => parseFormItems($_POST['items'] ?? []),
    ];

    try {
        $isChatwoot = ($_POST['source'] ?? '') === 'chatwoot';

        if ($isChatwoot) {
            $chatwootId = trim((string) ($_POST['chatwoot_contact_id'] ?? ''));
            if ($input['phone'] === '' && $input['email'] === '' && $chatwootId === '' && $input['client_name'] === '') {
                throw new InvalidArgumentException(
                    'Chatwoot contact has no usable details. Add a name, phone, or email in Chatwoot.'
                );
            }
        } elseif ($input['phone'] === '') {
            throw new InvalidArgumentException('Phone number is required.');
        }

        $service = makeService();
        $lineItems = $service->resolveLineItems($input['items']);

        $chatwootId = trim((string) ($_POST['chatwoot_contact_id'] ?? ''));
        $chatwootIdInt = $chatwootId !== '' ? (int) $chatwootId : null;

        $client = $service->ensureClient(
            $input['phone'] !== '' ? $input['phone'] : null,
            $input['client_name'] !== '' ? $input['client_name'] : null,
            $input['email'] !== '' ? $input['email'] : null,
            $chatwootIdInt,
        );

        $clientId = (string) ($client['id'] ?? '');

        $invoiceDate = $isChatwoot ? null : ($input['invoice_date'] !== '' ? $input['invoice_date'] : null);
        $dueDate = $isChatwoot ? null : ($input['due_date'] !== '' ? $input['due_date'] : null);

        $createdInvoice = $service->createInvoiceForClient(
            $clientId,
            $lineItems,
            $invoiceDate,
            $dueDate,
        );

        $recentInvoices = $service->listInvoicesByClientId($clientId);
        $success = 'Invoice created successfully.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$itemsJson = json_encode($input['items'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Invoice · Invoice Ninja</title>
    <link rel="stylesheet" href="/css/invoice.css">
</head>
<body data-products-url="/api/products.php">
    <div class="page">
        <header>
            <h1 id="page-title">Create invoice</h1>
            <p id="page-subtitle">Pick products from your catalog or enter a new name — missing products are created automatically.</p>
        </header>

        <div id="server-alerts">
        <?php if ($error !== null): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== null): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>
        </div>

        <p id="chatwoot-status" class="chatwoot-status chatwoot-status--loading" hidden>Loading contact from Chatwoot…</p>

        <div id="chatwoot-app" class="chatwoot-app" hidden>
            <section id="chatwoot-home" class="card chatwoot-home">
                <div id="chatwoot-invoices-list" class="chatwoot-invoices-list"></div>
                <div class="actions">
                    <button type="button" id="chatwoot-new-invoice-btn" class="btn-primary" disabled>
                        + New invoice
                    </button>
                </div>
            </section>
        </div>

        <div id="chatwoot-form-alert" class="alert alert-error" hidden></div>

        <?php if ($createdInvoice !== null): ?>
            <section class="card" id="created-invoice-section">
                <h2>Created invoice</h2>
                <?php if (!empty($createdInvoice['id'])): ?>
                    <p class="hint">
                        <button type="button" class="link-btn invoice-view-btn"
                                data-invoice-id="<?= e((string) $createdInvoice['id']) ?>">
                            View full details
                        </button>
                    </p>
                <?php endif; ?>
                <dl class="result-meta">
                    <div>
                        <dt>Invoice number</dt>
                        <dd><?= e((string) ($createdInvoice['number'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt>Amount</dt>
                        <dd><?= e((string) ($createdInvoice['amount'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt>Balance due</dt>
                        <dd><?= e((string) ($createdInvoice['balance'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt>Due date</dt>
                        <dd><?= e((string) ($createdInvoice['due_date'] ?? '—')) ?></dd>
                    </div>
                </dl>
            </section>
        <?php endif; ?>

        <form class="card" method="post" action="">
            <input type="hidden" name="source" id="source" value="">
            <input type="hidden" name="chatwoot_contact_id" id="chatwoot_contact_id" value="">

            <h2 id="client-section-heading">Client</h2>


            <div id="client-section-manual" class="grid">
                <div>
                    <label for="phone">Phone <span class="req">*</span></label>
                    <input type="tel" id="phone" name="phone"
                           placeholder="+966501234567" value="<?= e($input['phone']) ?>"
                           autocomplete="tel">
                    <p class="hint field-hint">Enter a number — client name, email, and invoices load automatically.</p>
                </div>
                <div class="load-invoices-bar">
                    <button type="button" id="load-invoices-btn" class="btn-secondary">Load invoices for this number</button>
                    <p id="load-invoices-status" class="load-invoices-status" hidden></p>
                </div>
                <div class="grid grid-2">
                    <div>
                        <label for="client_name">Client name</label>
                        <input type="text" id="client_name" name="client_name"
                               placeholder="Used when creating a new client"
                               value="<?= e($input['client_name']) ?>">
                    </div>
                    <div>
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email"
                               placeholder="optional@example.com"
                               value="<?= e($input['email']) ?>">
                    </div>
                </div>
                <div class="grid grid-2">
                    <div>
                        <label for="invoice_date">Invoice date</label>
                        <input type="date" id="invoice_date" name="invoice_date"
                               value="<?= e($input['invoice_date']) ?>">
                    </div>
                    <div>
                        <label for="due_date">Due date</label>
                        <input type="date" id="due_date" name="due_date"
                               value="<?= e($input['due_date']) ?>">
                    </div>
                </div>
            </div>

            <div id="invoice-form-panel">
                <div class="chatwoot-form-toolbar">
                    <button type="button" id="chatwoot-back-btn" class="btn-secondary btn-back" hidden>
                        ← Back to invoices
                    </button>
                </div>

                <h2 class="section-gap">Line items</h2>
                <p class="hint form-hint">Start typing a product name to load from Invoice Ninja. If it does not exist, it will be created when you submit.</p>

                <div id="line-items" class="line-items" data-initial-items="<?= e((string) $itemsJson) ?>"></div>

                <div class="actions actions-inline">
                    <button type="button" id="add-line-item" class="btn-secondary">+ Add line item</button>
                </div>

                <div class="actions">
                    <button type="submit">Create invoice</button>
                </div>
            </div>
        </form>

        <section id="loaded-invoices-section" class="card" hidden>
            <h2>Invoices for this client</h2>
            <p class="hint">Click a row to view line items and details.</p>
            <div id="loaded-invoices-wrap"></div>
        </section>

        <?php if ($recentInvoices !== []): ?>
            <?php
            $serviceForList = makeService();
            $invoiceSummaries = array_map(
                static fn (array $inv): array => $serviceForList->summarizeInvoice($inv),
                $recentInvoices
            );
            ?>
            <section class="card" id="client-invoices-list">
                <h2>Client invoices (<?= count($invoiceSummaries) ?>)</h2>
                <p class="hint">Click an invoice to view line items and details.</p>
                <div class="invoice-list-wrap" data-invoices="<?= e((string) json_encode($invoiceSummaries, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>"></div>
            </section>
        <?php elseif ($success !== null && $recentInvoices === []): ?>
            <section class="card">
                <p class="empty">No other invoices found for this phone.</p>
            </section>
        <?php endif; ?>
    </div>

    <template id="line-item-template">
        <div class="line-item-row">
            <div class="line-item-grid">
                <div class="field-product">
                    <label>Product <span class="req">*</span></label>
                    <input type="text" data-field="product_name" required
                           placeholder="Search or type new product" autocomplete="off">
                    <span class="product-suggestions"></span>
                </div>
                <div class="field-notes">
                    <label>Description</label>
                    <input type="text" data-field="notes" placeholder="Optional notes">
                </div>
                <div class="field-qty">
                    <label>Qty</label>
                    <input type="number" data-field="quantity" min="0.01" step="0.01" value="1" required>
                </div>
                <div class="field-cost">
                    <label>Unit price</label>
                    <input type="number" data-field="cost" min="0" step="0.01" required placeholder="0.00">
                </div>
                <div class="field-actions">
                    <label>&nbsp;</label>
                    <button type="button" class="btn-icon" data-action="remove" title="Remove line">×</button>
                </div>
            </div>
        </div>
    </template>

    <div id="invoice-modal" class="modal" hidden>
        <div class="modal-backdrop" data-action="close"></div>
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="invoice-modal-title">
            <button type="button" class="modal-close" data-action="close" aria-label="Close">×</button>
            <div id="invoice-modal-body" class="modal-body">
                <p class="empty">Loading…</p>
            </div>
        </div>
    </div>

    <script src="/js/invoice-detail.js" defer></script>
    <script src="/js/chatwoot-bridge.js" defer></script>
    <script src="/js/invoice-form.js" defer></script>
    <script src="/js/invoice-list.js" defer></script>
    <script src="/js/load-invoices.js" defer></script>
</body>
</html>
