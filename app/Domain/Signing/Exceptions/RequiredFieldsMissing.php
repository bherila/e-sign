<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

/**
 * A recipient tried to accept without completing every required field they own.
 *
 * docs/ARCHITECTURE.md invariant 3: required fields are validated on the server, and a
 * client-side "complete" flag has no authority. The gate is re-run at acceptance and not
 * only when a value is submitted, because the two are separate requests and nothing
 * guarantees the second followed the first.
 */
final class RequiredFieldsMissing extends SigningException
{
    /**
     * @param  list<string>  $fieldIds
     */
    public function __construct(
        public readonly string $recipientId,
        public readonly array $fieldIds,
    ) {
        parent::__construct(sprintf(
            'Recipient "%s" has not completed required field(s): %s.',
            $recipientId,
            implode(', ', $fieldIds),
        ));
    }

    public function code(): string
    {
        return 'required_fields_missing';
    }
}
