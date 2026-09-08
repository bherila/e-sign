<?php

declare(strict_types=1);

namespace App\Http\Requests\Signing;

use App\Domain\Signing\Sessions\OtpChallenges;
use App\Domain\Signing\Sessions\ReturnUrlPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /sign/{envelope}/{token}/start — the Continue button on the landing page.
 *
 * The token is a route parameter and is deliberately *not* validated here. Whether a
 * credential is usable is not a shape question: it is a database lookup, a hash comparison,
 * and three timestamps, and the answer is a 403 page rather than a field error. Putting it
 * in `rules()` would return a validation message naming the token, which is the one string
 * that must never come back out.
 *
 * `code` is optional because the same endpoint serves both halves of an OTP deployment: the
 * first submission has no code and gets one mailed, the second carries it. Its shape is
 * checked only loosely — anything of the right length and character class reaches
 * {@see OtpChallenges::verify()}, which counts the attempt.
 * Rejecting a malformed code here without counting it would give an attacker a free probe.
 *
 * `return` is accepted as an arbitrary string and validated by {@see ReturnUrlPolicy}, which
 * ignores anything not on the allowlist rather than erroring. A validation rule here would
 * turn the parameter into a probe for what the allowlist contains, and would stop a signer
 * finishing an agreement because somebody else's integration sent a bad URL.
 */
class StartSigningSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization for a guest is the credential and the session, resolved in the
        // controller and the middleware. There is no user to ask a policy about.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:16'],
            'return' => ['nullable', 'string', 'max:'.ReturnUrlPolicy::MAX_LENGTH],
        ];
    }

    public function code(): ?string
    {
        $code = $this->string('code')->trim()->value();

        return $code === '' ? null : $code;
    }

    /** The raw candidate. Never trusted; {@see ReturnUrlPolicy} decides. */
    public function returnCandidate(): ?string
    {
        $candidate = $this->string('return')->trim()->value();

        return $candidate === '' ? null : $candidate;
    }
}
