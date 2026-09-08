<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

/**
 * One party of an inbound `POST /signing-requests[/create-and-send]`, resolved.
 *
 * The profile sends `first_name`, an optional `last_name`, an `email`, a `designation` and an
 * `order`, and lets a caller refer to a recipient from a field by a temporary id
 * (`temp_1`, `temp_2`…). This object is what that becomes once the request has been read:
 * a **schema recipient id** the field schema will use as the field owner's handle, the
 * display name, the address, and the stage.
 *
 * `schemaId` is generated here (`r1`, `r2`, …) and is deliberately not the caller's temporary
 * id: a temporary id is per-request and may collide with a name that means something else in
 * the schema's identifier space. The mapping back to whatever the caller called them is kept
 * on {@see $reference} so a field can be matched and so an error can name the recipient the
 * way the caller does.
 *
 * The name is joined from the parts rather than split into them. Upstream auto-constructs
 * `name` and overwrites anything supplied; this product stores one display name and never
 * guesses which part of a person's name is which (`docs/HANDOFF.md` §2), so joining is safe
 * in a way splitting never is.
 */
final readonly class PlannedRecipient
{
    /**
     * @param  string  $schemaId  The field schema's handle for this party.
     * @param  int  $order  1-based signing order, required by disagreement D3.
     * @param  list<string>  $reference  Every way the caller may refer to this party: its
     *                                   temporary id, its email, and its order as a string.
     */
    public function __construct(
        public string $schemaId,
        public string $name,
        public string $email,
        public int $order,
        public array $reference,
    ) {}

    /**
     * @return array{id: string, name: string, email: string}
     */
    public function toSchemaRecipient(): array
    {
        return ['id' => $this->schemaId, 'name' => $this->name, 'email' => $this->email];
    }

    /**
     * @return array{id: string, name: string, email: string}
     */
    public function toContactOverride(): array
    {
        return $this->toSchemaRecipient();
    }

    public function answersTo(string $reference): bool
    {
        return in_array(mb_strtolower(trim($reference)), $this->reference, true);
    }
}
