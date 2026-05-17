<?php

declare(strict_types=1);

namespace App;

use InvoiceNinja\Sdk\InvoiceNinja;

final class InvoiceNinjaService
{
    private InvoiceNinja $ninja;

    public function __construct(string $apiToken, string $baseUrl)
    {
        $this->ninja = new InvoiceNinja($apiToken);
        $this->ninja->setUrl(rtrim($baseUrl, '/'));
    }

    /**
     * Find clients whose client.phone or any contact.phone matches (digits-only compare).
     *
     * @return list<array<string, mixed>>
     */
    public function findClientsByPhone(string $phone): array
    {
        if (self::normalizePhone($phone) === '') {
            throw new \InvalidArgumentException('Phone number is empty or invalid.');
        }

        $local = self::phoneWithoutCountryCode($phone);
        $searchTerms = array_values(array_unique(array_filter([
            $local,
            self::normalizePhone($phone),
            strlen($local) >= 9 ? substr($local, -9) : null,
        ])));

        $matches = [];
        $seenIds = [];

        foreach ($searchTerms as $term) {
            $response = $this->ninja->clients->all([
                'filter' => $term,
                'include' => 'contacts',
                'per_page' => 100,
                'is_deleted' => false,
            ]);

            foreach ($response['data'] ?? [] as $client) {
                if (!self::isClientActive($client)) {
                    continue;
                }
                $id = (string) ($client['id'] ?? '');
                if ($id !== '' && isset($seenIds[$id])) {
                    continue;
                }
                if (!$this->clientMatchesPhone($client, $phone)) {
                    continue;
                }
                if ($id !== '') {
                    $seenIds[$id] = true;
                }
                $matches[] = $client;
            }
        }

        return $matches;
    }

    /**
     * Pick the best active client for a phone (exact match preferred, then most invoices).
     *
     * @param list<array<string, mixed>> $clients
     * @return array<string, mixed>|null
     */
    public function pickBestClientForPhone(array $clients, string $phone): ?array
    {
        $active = array_values(array_filter($clients, static fn (array $c): bool => self::isClientActive($c)));
        if ($active === []) {
            return null;
        }
        if (count($active) === 1) {
            return $active[0];
        }

        $best = null;
        $bestScore = -1;

        foreach ($active as $client) {
            $score = $this->clientPhoneMatchScore($client, $phone);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $client;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $client
     */
    public static function isClientActive(array $client): bool
    {
        if (!empty($client['is_deleted'])) {
            return false;
        }

        $deletedAt = $client['deleted_at'] ?? 0;

        return !is_numeric($deletedAt) || (int) $deletedAt <= 0;
    }

    /**
     * @param array<string, mixed> $client
     */
    public function clientId(array $client): string
    {
        return trim((string) ($client['id'] ?? ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInvoicesByPhone(string $phone): array
    {
        $client = $this->pickBestClientForPhone($this->findClientsByPhone($phone), $phone);
        if ($client === null) {
            return [];
        }

        return $this->listInvoicesByClientId($this->clientId($client));
    }

    /**
     * Find or create an Invoice Ninja client from Chatwoot / form contact fields.
     *
     * @return array<string, mixed> Client record from Invoice Ninja
     */
    public function ensureClient(
        ?string $phone,
        ?string $name = null,
        ?string $email = null,
        ?int $chatwootContactId = null,
    ): array {
        $phone = trim((string) $phone);
        $name = trim((string) $name);
        $email = trim((string) $email);

        if ($phone === '' && $email === '' && $chatwootContactId === null && $name === '') {
            throw new \InvalidArgumentException(
                'Contact must have a phone number, email, Chatwoot id, or name.'
            );
        }

        if ($chatwootContactId !== null) {
            $byChatwoot = $this->findClientByChatwootContactId($chatwootContactId);
            if ($byChatwoot !== null) {
                return $byChatwoot;
            }
        }

        if ($phone !== '') {
            $best = $this->pickBestClientForPhone($this->findClientsByPhone($phone), $phone);
            if ($best !== null) {
                return $best;
            }
        }

        if ($email !== '') {
            $existing = $this->findClientByEmail($email);
            if ($existing !== null) {
                return $existing;
            }
        }

        $created = $this->createClient($phone, $name, $email, $chatwootContactId);

        return $created['data'] ?? $created;
    }

    public function ensureClientFromChatwoot(ChatwootContact $contact): array
    {
        if (!$contact->hasLookupKey()) {
            throw new \InvalidArgumentException(
                'This Chatwoot contact has no usable details. Add a name, phone, or email in Chatwoot.'
            );
        }

        if ($contact->id !== null) {
            $byChatwoot = $this->findClientByChatwootContactId($contact->id);
            if ($byChatwoot !== null) {
                return $byChatwoot;
            }
        }

        return $this->ensureClient(
            $contact->phone !== '' ? $contact->phone : null,
            $contact->name !== '' ? $contact->name : null,
            $contact->email !== '' ? $contact->email : null,
            $contact->id,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClientByChatwootContactId(int $chatwootContactId): ?array
    {
        if ($chatwootContactId <= 0) {
            return null;
        }

        $marker = self::chatwootContactMarker($chatwootContactId);

        $response = $this->ninja->clients->all([
            'filter' => $marker,
            'include' => 'contacts',
            'per_page' => 20,
        ]);

        foreach ($response['data'] ?? [] as $client) {
            if (!self::isClientActive($client)) {
                continue;
            }
            if (self::clientHasChatwootMarker($client, $chatwootContactId)) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClientByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $response = $this->ninja->clients->all([
            'email' => $email,
            'include' => 'contacts',
            'per_page' => 10,
        ]);

        foreach ($response['data'] ?? [] as $client) {
            if (!self::isClientActive($client)) {
                continue;
            }
            foreach ($client['contacts'] ?? [] as $contact) {
                if (strcasecmp(trim((string) ($contact['email'] ?? '')), $email) === 0) {
                    return $client;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $lineItems
     * @return array<string, mixed>
     */
    public function createInvoiceForClient(
        string $clientId,
        array $lineItems,
        ?string $invoiceDate = null,
        ?string $dueDate = null,
    ): array {
        if ($clientId === '') {
            throw new \RuntimeException('Could not resolve client_id for invoice.');
        }

        $response = $this->ninja->invoices->create([
            'client_id' => $clientId,
            'date' => $invoiceDate ?? date('Y-m-d'),
            'due_date' => $dueDate ?? date('Y-m-d', strtotime('+30 days')),
            'line_items' => $lineItems,
        ]);

        return $response['data'] ?? $response;
    }

    /**
     * @param list<array<string, mixed>> $lineItems
     * @return array<string, mixed>
     */
    public function createInvoiceForPhone(
        string $phone,
        array $lineItems,
        ?string $clientName = null,
        ?string $contactEmail = null,
        ?string $invoiceDate = null,
        ?string $dueDate = null,
    ): array {
        $client = $this->ensureClient($phone, $clientName, $contactEmail);

        return $this->createInvoiceForClient(
            (string) ($client['id'] ?? ''),
            $lineItems,
            $invoiceDate,
            $dueDate,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInvoicesByClientId(string $clientId): array
    {
        if ($clientId === '') {
            return [];
        }

        $invoices = [];
        $page = 1;

        do {
            $response = $this->ninja->invoices->all([
                'client_id' => $clientId,
                'per_page' => 100,
                'page' => $page,
            ]);

            foreach ($response['data'] ?? [] as $invoice) {
                $invoices[] = $invoice;
            }

            $hasMore = ($response['meta']['pagination']['current_page'] ?? $page)
                < ($response['meta']['pagination']['last_page'] ?? $page);
            $page++;
        } while ($hasMore);

        return $invoices;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoice(string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            throw new \InvalidArgumentException('Invoice id is required.');
        }

        $response = $this->ninja->invoices->get($invoiceId);
        $invoice = $response['data'] ?? $response;

        return $this->formatInvoiceDetail($invoice);
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    public function formatInvoiceDetail(array $invoice): array
    {
        $lineItems = [];
        foreach ($invoice['line_items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = (float) ($item['quantity'] ?? 0);
            $cost = (float) ($item['cost'] ?? 0);
            $lineItems[] = [
                'product_key' => (string) ($item['product_key'] ?? ''),
                'notes' => (string) ($item['notes'] ?? ''),
                'quantity' => $qty,
                'cost' => $cost,
                'line_total' => (float) ($item['line_total'] ?? $qty * $cost),
            ];
        }

        return [
            'id' => $invoice['id'] ?? null,
            'number' => $invoice['number'] ?? null,
            'status' => self::invoiceStatusLabel((string) ($invoice['status_id'] ?? '')),
            'status_id' => $invoice['status_id'] ?? null,
            'amount' => $invoice['amount'] ?? null,
            'balance' => $invoice['balance'] ?? null,
            'date' => $invoice['date'] ?? null,
            'due_date' => $invoice['due_date'] ?? null,
            'discount' => $invoice['discount'] ?? 0,
            'terms' => $invoice['terms'] ?? '',
            'public_notes' => $invoice['public_notes'] ?? '',
            'line_items' => $lineItems,
        ];
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    public function summarizeInvoice(array $invoice): array
    {
        return [
            'id' => $invoice['id'] ?? null,
            'number' => $invoice['number'] ?? null,
            'amount' => $invoice['amount'] ?? null,
            'balance' => $invoice['balance'] ?? null,
            'due_date' => $invoice['due_date'] ?? null,
            'date' => $invoice['date'] ?? null,
            'status' => self::invoiceStatusLabel((string) ($invoice['status_id'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function formatClientForForm(array $client): array
    {
        $email = '';
        $contactName = '';

        foreach ($client['contacts'] ?? [] as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            if (!empty($contact['is_primary'])) {
                $email = trim((string) ($contact['email'] ?? ''));
                $contactName = trim(
                    ((string) ($contact['first_name'] ?? '')) . ' ' . ((string) ($contact['last_name'] ?? ''))
                );
                break;
            }
        }

        if ($email === '' && isset($client['contacts'][0]) && is_array($client['contacts'][0])) {
            $email = trim((string) ($client['contacts'][0]['email'] ?? ''));
            $contactName = trim(
                ((string) ($client['contacts'][0]['first_name'] ?? '')) . ' '
                . ((string) ($client['contacts'][0]['last_name'] ?? ''))
            );
        }

        $name = trim((string) ($client['name'] ?? $client['display_name'] ?? ''));
        if ($name === '' && $contactName !== '') {
            $name = $contactName;
        }

        return [
            'id' => $client['id'] ?? null,
            'name' => $name,
            'email' => $email,
            'phone' => trim((string) ($client['phone'] ?? '')),
        ];
    }

    public static function invoiceStatusLabel(string $statusId): string
    {
        return match ($statusId) {
            '1' => 'Draft',
            '2' => 'Sent',
            '3' => 'Partial',
            '4' => 'Paid',
            '5' => 'Cancelled',
            '6' => 'Reversed',
            default => $statusId !== '' ? 'Status ' . $statusId : 'Unknown',
        };
    }

    /**
     * @return array{client: array<string, mixed>, created: bool, invoices: list<array<string, mixed>>}
     */
    public function syncChatwootContact(ChatwootContact $contact): array
    {
        if (!$contact->hasLookupKey()) {
            throw new \InvalidArgumentException(
                'This Chatwoot contact has no usable details. Add a name, phone, or email in Chatwoot.'
            );
        }

        $phone = $contact->phone;
        $email = $contact->email;

        $existing = null;
        if ($contact->id !== null) {
            $existing = $this->findClientByChatwootContactId($contact->id);
        }
        if ($existing === null && $phone !== '') {
            $existing = $this->pickBestClientForPhone($this->findClientsByPhone($phone), $phone);
        }
        if ($existing === null && $email !== '') {
            $existing = $this->findClientByEmail($email);
        }

        $created = false;
        if ($existing === null) {
            $existing = $this->ensureClientFromChatwoot($contact);
            $created = true;
        } elseif ($contact->id !== null && !self::clientHasChatwootMarker($existing, $contact->id)) {
            $existing = $this->tagClientWithChatwootId($existing, $contact->id);
        }

        $clientId = $this->clientId($existing);
        if ($clientId === '' || !self::isClientActive($existing)) {
            throw new \RuntimeException('Could not resolve an active Invoice Ninja client for this contact.');
        }

        return [
            'client' => $existing,
            'created' => $created,
            'invoices' => $this->listInvoicesByClientId($clientId),
        ];
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function tagClientWithChatwootId(array $client, int $chatwootContactId): array
    {
        $clientId = (string) ($client['id'] ?? '');
        if ($clientId === '') {
            return $client;
        }

        $response = $this->ninja->clients->update($clientId, [
            'custom_value1' => self::chatwootContactMarker($chatwootContactId),
        ]);

        return $response['data'] ?? $response;
    }

    private function createClient(
        string $phone,
        ?string $name,
        ?string $email,
        ?int $chatwootContactId = null,
    ): array {
        $displayName = $name !== '' ? $name : ($email !== '' ? $email : ($phone !== '' ? 'Client ' . $phone : (
            $chatwootContactId !== null ? 'Chatwoot contact #' . $chatwootContactId : 'Chatwoot contact'
        )));

        $contactEmail = $email !== ''
            ? $email
            : ($phone !== ''
                ? sprintf('client-%s@example.invalid', self::normalizePhone($phone))
                : sprintf('chatwoot-%s@example.invalid', $chatwootContactId ?? uniqid()));

        $payload = [
            'name' => $displayName,
            'contacts' => [
                [
                    'first_name' => $displayName,
                    'last_name' => '',
                    'phone' => $phone,
                    'email' => $contactEmail,
                    'send_email' => false,
                    'is_primary' => true,
                ],
            ],
        ];

        if ($phone !== '') {
            $payload['phone'] = $phone;
        }

        if ($chatwootContactId !== null) {
            $payload['custom_value1'] = self::chatwootContactMarker($chatwootContactId);
        }

        return $this->ninja->clients->create($payload);
    }

    private static function chatwootContactMarker(int $chatwootContactId): string
    {
        return 'chatwoot_contact_id:' . $chatwootContactId;
    }

    /**
     * @param array<string, mixed> $client
     */
    private static function clientHasChatwootMarker(array $client, int $chatwootContactId): bool
    {
        return trim((string) ($client['custom_value1'] ?? '')) === self::chatwootContactMarker($chatwootContactId);
    }

    private function clientMatchesPhone(array $client, string $inputPhone): bool
    {
        $phones = [];
        $clientPhone = trim((string) ($client['phone'] ?? ''));
        if ($clientPhone !== '') {
            $phones[] = $clientPhone;
        }

        foreach ($client['contacts'] ?? [] as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $contactPhone = trim((string) ($contact['phone'] ?? ''));
            if ($contactPhone !== '') {
                $phones[] = $contactPhone;
            }
        }

        foreach ($phones as $stored) {
            if (self::phonesMatch($stored, $inputPhone)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * Digits-only local number (no country code, no leading trunk 0).
     */
    public static function phoneWithoutCountryCode(string $phone): string
    {
        $digits = ltrim(self::normalizePhone($phone), '0');
        if ($digits === '') {
            return '';
        }

        if (str_contains($phone, '+')) {
            foreach (self::countryCallingCodes() as $code) {
                $codeLen = strlen($code);
                if (str_starts_with($digits, $code) && strlen($digits) > $codeLen + 6) {
                    return substr($digits, $codeLen);
                }
            }

            return $digits;
        }

        // National format (no +): only strip country digits when length proves an embedded prefix.
        foreach (self::nationalPrefixCountryCodes() as $code) {
            $codeLen = strlen($code);
            if (str_starts_with($digits, $code) && strlen($digits) >= $codeLen + 10) {
                return substr($digits, $codeLen);
            }
        }

        return $digits;
    }

    /**
     * True when both numbers share the same local part (country code ignored).
     */
    public static function phonesMatch(string $phoneA, string $phoneB): bool
    {
        $a = self::normalizePhone($phoneA);
        $b = self::normalizePhone($phoneB);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $localA = self::phoneWithoutCountryCode($phoneA);
        $localB = self::phoneWithoutCountryCode($phoneB);

        return $localA !== '' && $localB !== '' && $localA === $localB;
    }

    /**
     * @param array<string, mixed> $client
     */
    private function clientPhoneMatchScore(array $client, string $phone): int
    {
        $score = 0;
        $inputNorm = self::normalizePhone($phone);
        $inputLocal = self::phoneWithoutCountryCode($phone);

        $phones = [];
        $clientPhone = trim((string) ($client['phone'] ?? ''));
        if ($clientPhone !== '') {
            $phones[] = $clientPhone;
        }
        foreach ($client['contacts'] ?? [] as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $contactPhone = trim((string) ($contact['phone'] ?? ''));
            if ($contactPhone !== '') {
                $phones[] = $contactPhone;
            }
        }

        foreach ($phones as $stored) {
            if (self::normalizePhone($stored) === $inputNorm) {
                $score += 100;
            }
            if (self::phoneWithoutCountryCode($stored) === $inputLocal) {
                $score += 50;
            }
        }

        $clientId = $this->clientId($client);
        if ($clientId !== '') {
            $score += count($this->listInvoicesByClientId($clientId)) * 5;
        }

        return $score;
    }

    /**
     * Country codes often stored without + on national numbers (98, 966, 964, …).
     *
     * @return list<string>
     */
    private static function nationalPrefixCountryCodes(): array
    {
        return ['966', '971', '973', '974', '965', '968', '964', '963', '962', '961', '98', '90', '20'];
    }

    /**
     * ITU calling codes, longest first so e.g. 966 wins over 96.
     *
     * @return list<string>
     */
    private static function countryCallingCodes(): array
    {
        static $codes = null;
        if ($codes !== null) {
            return $codes;
        }

        $codes = [
            '966', '971', '973', '974', '965', '968', '962', '961', '964', '963', '967', '970', '972',
            '880', '886', '852', '853', '855', '856', '880', '886', '880', '886',
            '351', '352', '353', '354', '355', '356', '357', '358', '359', '370', '371', '372', '373',
            '374', '375', '376', '377', '378', '380', '381', '382', '385', '386', '387', '389',
            '420', '421', '423',
            '212', '213', '216', '218', '220', '221', '222', '223', '224', '225', '226', '227', '228',
            '229', '230', '231', '232', '233', '234', '235', '236', '237', '238', '239', '240', '241',
            '242', '243', '244', '245', '246', '248', '249', '250', '251', '252', '253', '254', '255',
            '256', '257', '258', '260', '261', '262', '263', '264', '265', '266', '267', '268', '269',
            '290', '291', '297', '298', '299',
            '44', '49', '33', '39', '34', '31', '32', '41', '43', '45', '46', '47', '48', '30', '36',
            '60', '61', '62', '63', '64', '65', '66', '81', '82', '84', '86', '90', '91', '92', '93',
            '94', '95', '98',
            '1', '7', '20', '27',
        ];

        usort($codes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $codes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listProducts(?string $search = null): array
    {
        $params = ['per_page' => 100];
        if ($search !== null && trim($search) !== '') {
            $params['filter'] = trim($search);
        }

        $response = $this->ninja->products->all($params);

        return $response['data'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findProductByName(string $name): ?array
    {
        $needle = self::normalizeProductName($name);
        if ($needle === '') {
            return null;
        }

        foreach ($this->listProducts($name) as $product) {
            if (self::normalizeProductName((string) ($product['product_key'] ?? '')) === $needle) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function findOrCreateProduct(string $name, float $price, string $notes = ''): array
    {
        $existing = $this->findProductByName($name);
        if ($existing !== null) {
            return $existing;
        }

        $payload = [
            'product_key' => trim($name),
            'price' => $price,
            'cost' => 0,
        ];

        if (trim($notes) !== '') {
            $payload['notes'] = trim($notes);
        }

        $response = $this->ninja->products->create($payload);

        return $response['data'] ?? $response;
    }

    /**
     * Resolve form rows to invoice line_items; creates missing products by name.
     *
     * @param list<array<string, mixed>> $formItems
     * @return list<array<string, mixed>>
     */
    public function resolveLineItems(array $formItems): array
    {
        $lineItems = [];

        foreach ($formItems as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['product_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $notes = trim((string) ($row['notes'] ?? ''));
            $quantity = (float) ($row['quantity'] ?? 1);
            $cost = (float) ($row['cost'] ?? 0);

            if ($quantity <= 0) {
                throw new \InvalidArgumentException("Quantity must be greater than zero for \"{$name}\".");
            }
            if ($cost < 0) {
                throw new \InvalidArgumentException("Price cannot be negative for \"{$name}\".");
            }

            $product = $this->findOrCreateProduct($name, $cost, $notes);
            $productKey = (string) ($product['product_key'] ?? $name);

            $lineItems[] = [
                'product_key' => $productKey,
                'notes' => $notes !== '' ? $notes : (string) ($product['notes'] ?? ''),
                'quantity' => $quantity,
                'cost' => $cost,
            ];
        }

        if ($lineItems === []) {
            throw new \InvalidArgumentException('Add at least one line item with a product name.');
        }

        return $lineItems;
    }

    public static function normalizeProductName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
