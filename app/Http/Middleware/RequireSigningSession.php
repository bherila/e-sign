<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Sessions\Exceptions\GuestAccessDenied;
use App\Domain\Signing\Sessions\GuestSigningContext;
use App\Domain\Signing\Sessions\SigningSessionManager;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate in front of every signing route except the landing page.
 *
 * Three things have to be true, and all three are checked here rather than in six
 * controllers:
 *
 * 1. The request carries the `esign_signing` cookie and its verifier matches a session row
 *    that has not ended and has not expired.
 * 2. That session's envelope is the envelope named in the URL. A live session for one
 *    agreement is not an authorization for another, and this is where that is enforced —
 *    not by hoping every controller remembers to compare.
 * 3. The recipient the session speaks for still exists.
 *
 * What it deliberately does **not** check is whether the recipient may act right now. A
 * person who has already signed, or whose turn has not come, still needs to be able to load
 * the page and be told so; refusing them here would replace an explanation with a 403.
 * `GuestSigningContext::mayAct()` answers that question, and the two write endpoints ask it.
 *
 * A successful pass slides the session's expiry. That is a mutation on a GET, and it is the
 * one this module allows: it changes when a browser tab stops working and touches nothing
 * about the agreement — not the envelope, not the recipient, not a field value, not an
 * invitation. `HarmlessGetTest` pins that distinction by fetching every GET twice and
 * asserting the agreement is byte-identical afterwards.
 */
class RequireSigningSession
{
    public function __construct(
        private readonly SigningSessionManager $sessions,
        private readonly Application $app,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $envelope = $this->envelopeFor($request);

        try {
            $session = $this->sessions->require($request, $envelope);
            $recipient = $this->sessions->recipientFor($session);
        } catch (GuestAccessDenied $denied) {
            return $this->refuse($denied, $envelope);
        }

        $this->sessions->touch($session);

        $this->app->instance(
            GuestSigningContext::class,
            new GuestSigningContext($envelope, $recipient, $session),
        );

        return $next($request);
    }

    /**
     * The envelope the URL names, resolved here rather than by implicit binding.
     *
     * Laravel's `SubstituteBindings` only resolves a route parameter that some controller
     * method type-hints, and none of the session controllers needs an `Envelope` argument —
     * the session already knows which envelope it covers. Resolving it here keeps that true:
     * the scope check cannot be silently disabled by a controller signature changing, and a
     * route in this group cannot accidentally run with the parameter still a raw string.
     *
     * The resolved model is written back onto the route, so a controller that *does* want it
     * gets the same instance rather than a second query.
     */
    private function envelopeFor(Request $request): Envelope
    {
        $parameter = $request->route('envelope');

        if ($parameter instanceof Envelope) {
            return $parameter;
        }

        $envelope = is_string($parameter)
            ? Envelope::query()->where('public_id', $parameter)->first()
            : null;

        if (! $envelope instanceof Envelope) {
            abort(404);
        }

        $request->route()?->setParameter('envelope', $envelope);

        return $envelope;
    }

    /**
     * What a guest without a session is told.
     *
     * The same page for every reason, and it points back at the landing URL only when the
     * caller can construct one — which they cannot, because it needs the token. So the page
     * says to open the link from the invitation again, which is the one instruction that is
     * always correct and reveals nothing.
     */
    private function refuse(GuestAccessDenied $denied, Envelope $envelope): Response
    {
        return response()->view('signing.no-session', [
            'reason' => $denied->reason,
            'title' => $envelope->title,
        ], 403);
    }
}
