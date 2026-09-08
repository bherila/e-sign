<?php

declare(strict_types=1);

namespace App\Domain\Signing\Assurance;

use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;

/**
 * The real send-time check: the configured seal material actually loads, the certificate is
 * within its validity window and matches its private key, and a timestamp authority is
 * configured and addressable when B-T is asked for.
 *
 * docs/HANDOFF.md section 9 requires rejecting "absent, unusable, expired, or mismatched
 * configured signing material before inviting signers". {@see ConfiguredSealAssurancePolicyCheck}
 * answers the first of those four from the configuration alone — are the paths set, do the
 * files exist, is a TSA named — and it is kept because it produces the precise, actionable
 * message an operator wants for the common case of an unfinished install.
 *
 * The other three cannot be answered without opening the material, which is what
 * {@see PdfSealer::preflight()} does: it constructs the seal material, and constructing it
 * *is* the gate — an expired certificate, a key that does not match it, an unreadable
 * passphrase, and an unsupported digest algorithm are each a typed exception rather than a
 * value somebody has to remember to check. For B-T it additionally asserts the timestamp
 * authority is configured and passes the outbound destination policy.
 *
 * ## Cost, and why this is affordable on every send
 *
 * The deep check is local: a file read, an OpenSSL parse, and a URL/DNS check for the TSA.
 * It contacts no timestamp authority and signs nothing, so inviting a signer never waits on a
 * third party. The alternative — discovering at finalization that the certificate expired
 * last week — is discovered after people have already signed, which is the failure this
 * exists to prevent.
 *
 * ## Fail closed, always
 *
 * An unexpected exception is an unavailable answer, not an available one. There is no
 * configuration that declares a level available without the material behind it, and there is
 * no downgrade from B-T to B-B: a level that cannot be met is an error (AGENTS.md).
 */
final readonly class SealMaterialAssurancePolicyCheck implements AssurancePolicyCheck
{
    public function __construct(
        private AssurancePolicyCheck $configured,
        private PdfSealer $sealer,
    ) {}

    public function unavailableReason(AssuranceLevel $level): ?string
    {
        $configured = $this->configured->unavailableReason($level);

        if ($configured !== null) {
            return $configured;
        }

        try {
            $this->sealer->preflight($level);
        } catch (SealingException $exception) {
            return $exception->getMessage();
        } catch (\Throwable) {
            // Never null on uncertainty: an unknown answer is an unavailable one.
            //
            // And never the message. The typed SealingException messages above are written to
            // be path-free and are safe to forward — this branch is for whatever was not
            // anticipated, whose message could be an `ErrorException` naming the seal key's
            // path, and it reaches an `envelopes:write` caller through
            // SendPreconditionsFailed's `problems` array
            // (docs/security/review-2026-09.md finding E-5). The operator sees the real
            // reason in `esign:seal:status`, which is where a key path belongs.
            return 'The configured seal material could not be checked. Run esign:seal:status on the '
                .'deployment for the reason.';
        }

        return null;
    }
}
