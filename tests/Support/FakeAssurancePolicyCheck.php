<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;

/**
 * A stand-in for the seal-material check.
 *
 * The real check reads certificate and key paths off disk, which is not what a state-machine
 * test is about; the sealing tests cover the material itself with the synthetic keys in
 * `tests/Fixtures/crypto`. What matters here is that an envelope cannot be sent when the
 * check says no, and this makes both answers trivial to arrange.
 */
final class FakeAssurancePolicyCheck implements AssurancePolicyCheck
{
    /** @var list<AssuranceLevel> */
    public array $asked = [];

    private function __construct(private readonly ?string $reason) {}

    public static function available(): self
    {
        return new self(null);
    }

    public static function unavailable(string $reason = 'No seal material is configured.'): self
    {
        return new self($reason);
    }

    public function unavailableReason(AssuranceLevel $level): ?string
    {
        $this->asked[] = $level;

        return $this->reason;
    }
}
