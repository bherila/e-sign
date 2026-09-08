<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

/**
 * The envelope is not ready to be sent, with every reason at once.
 *
 * Reporting all of them in one pass is the same rule the field-schema importer follows: a
 * sender fixing an envelope should see the whole list, not discover the next problem after
 * fixing the previous one. It is also what the Firma profile's two-phase validation surfaces
 * (`validation_errors[]` in `CreateAndSendValidationError`), so the facade can map this
 * straight across.
 *
 * Send is the last moment anything can be fixed cheaply. After it, correcting material
 * content means a new version and renewed signatures (docs/ARCHITECTURE.md invariant 4), so
 * this gate is deliberately strict.
 */
final class SendPreconditionsFailed extends SigningException
{
    /**
     * @param  list<array{code: string, message: string, recipient?: string, field?: string}>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(
            'The envelope cannot be sent: '
            .implode(' ', array_map(static fn (array $problem): string => $problem['message'], $problems)),
        );
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_map(
            static fn (array $problem): string => $problem['code'],
            $this->problems,
        ));
    }

    public function hasCode(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function code(): string
    {
        return 'send_preconditions_failed';
    }
}
