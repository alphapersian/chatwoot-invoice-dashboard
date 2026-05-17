<?php

declare(strict_types=1);

namespace App;

final readonly class ChatwootContact
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $email,
        public string $phone,
        public string $identifier = '',
    ) {
    }

    /**
     * @param array<string, mixed> $contact
     */
    public static function fromArray(array $contact): self
    {
        return new self(
            id: isset($contact['id']) ? (int) $contact['id'] : null,
            name: trim((string) ($contact['name'] ?? '')),
            email: trim((string) ($contact['email'] ?? '')),
            phone: trim((string) ($contact['phone_number'] ?? $contact['phone'] ?? '')),
            identifier: trim((string) ($contact['identifier'] ?? '')),
        );
    }

    /**
     * @param array<string, mixed> $context Chatwoot appContext.data
     */
    public static function fromAppContext(array $context): ?self
    {
        if (isset($context['contact']) && is_array($context['contact'])) {
            return self::fromArray($context['contact']);
        }

        $sender = $context['conversation']['meta']['sender'] ?? null;
        if (is_array($sender)) {
            return self::fromArray($sender);
        }

        return null;
    }

    public function hasLookupKey(): bool
    {
        return $this->phone !== '' || $this->email !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientPayload(): array
    {
        return [
            'chatwoot_contact_id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'identifier' => $this->identifier,
        ];
    }
}
