<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use App\Domain\Signing\Models\Envelope;
use Illuminate\Contracts\Config\Repository;

/**
 * Whether this envelope's guests have to answer a mailed code as well as follow the link.
 *
 * Three levels, most specific first: the envelope, the workspace that sent it, then the
 * deployment default in `config('esign.signing.require_otp')`. Both columns are nullable and
 * null means "not decided here" rather than false, so an envelope that says nothing inherits
 * its workspace instead of silently overruling it. That distinction is the whole reason the
 * columns are nullable booleans rather than booleans with a default.
 *
 * The resolution is read-only and side-effect free. What was actually *applied* is recorded
 * on the attestation as its `verification_method`, so changing this setting later cannot
 * change the account of how somebody was let in.
 */
final class OtpRequirement
{
    public function __construct(private readonly Repository $config) {}

    public function for(Envelope $envelope): bool
    {
        if ($envelope->require_otp !== null) {
            return (bool) $envelope->require_otp;
        }

        $workspace = $envelope->workspace()->first();

        if ($workspace !== null && $workspace->require_otp !== null) {
            return (bool) $workspace->require_otp;
        }

        return (bool) $this->config->get('esign.signing.require_otp', false);
    }
}
