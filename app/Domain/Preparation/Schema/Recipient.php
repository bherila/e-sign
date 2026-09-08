<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

/**
 * A party the document is prepared for.
 *
 * The `id` is the stable handle fields and the signing order refer to; it survives import and
 * export byte-for-byte. `role` is a display label for the party ("Buyer", "Witness") and carries
 * no authority: application roles live in the Identity module, and identity binds on issuer plus
 * subject, never on the email address recorded here (AGENTS.md).
 */
final readonly class Recipient
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $role = null,
    ) {}

    /**
     * @param  array{id: string, name: string, email: string, role?: string}  $recipient
     */
    public static function fromArray(array $recipient): self
    {
        return new self(
            $recipient['id'],
            $recipient['name'],
            $recipient['email'],
            $recipient['role'] ?? null,
        );
    }

    /**
     * @return array{id: string, name: string, email: string, role?: string}
     */
    public function toArray(): array
    {
        $recipient = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];

        if ($this->role !== null) {
            $recipient['role'] = $this->role;
        }

        return $recipient;
    }
}
