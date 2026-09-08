<?php

declare(strict_types=1);

namespace App\Domain\Signing\Capture;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The electronic-signature consent notice a guest is shown before they can assent.
 *
 * The text lives in the repository, at `resources/views/signing/consent.md`, and not in the
 * database. A consent notice is drafted and approved outside this application — by counsel,
 * for a deployment — and it is reviewed, diffed, and released like code. Putting it in a
 * table would make "what were people shown last March?" a question about backups, and would
 * add a field a compromised administrator could rewrite between a signer reading it and the
 * attestation recording that they read it.
 *
 * Which *version* was displayed is a different question, and the envelope already answers
 * it: `envelopes.consent_policy_version` is snapshotted at creation and copied onto the
 * attestation. This class reads `config('esign.signing.consent_policy_version')` only to say
 * whether the file on disk is that version's text, and hands the answer to the page rather
 * than resolving it silently ({@see ConsentText}).
 *
 * The Markdown is converted with the framework's own CommonMark configuration and no raw
 * HTML passthrough, so a file edited into containing a `<script>` renders as the characters
 * of one. That matters less than the file being in the repository at all, but the two
 * together mean the notice cannot become an injection point.
 */
final class ConsentPolicy
{
    /** The single source of the notice, relative to the application root. */
    public const PATH = 'resources/views/signing/consent.md';

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $files,
    ) {}

    /** The version the deployment declares the text on disk to be. */
    public function textVersion(): string
    {
        $version = trim((string) $this->config->get('esign.signing.consent_policy_version', ''));

        if ($version === '') {
            throw new RuntimeException(
                'esign.signing.consent_policy_version is empty, so the consent notice cannot say '
                .'which version it is. Set ESIGN_CONSENT_POLICY_VERSION.',
            );
        }

        return $version;
    }

    /**
     * The notice, ready to render, paired with the envelope's recorded version.
     *
     * Fails loudly when the file is missing. A signing page that renders an empty consent
     * panel and still accepts an assent would be recording agreement to nothing, which is
     * the one failure mode this whole module exists to prevent.
     */
    public function forRecordedVersion(string $recordedVersion): ConsentText
    {
        return new ConsentText($recordedVersion, $this->textVersion(), $this->html());
    }

    public function markdown(): string
    {
        $path = base_path(self::PATH);

        if (! $this->files->isFile($path)) {
            throw new RuntimeException(
                'The consent notice is missing from '.self::PATH.'. A signing page cannot take '
                .'an assent it has no text to display.',
            );
        }

        return $this->files->get($path);
    }

    public function html(): string
    {
        // `html_input: 'escape'` rather than the default: the file is trusted, and it is
        // still cheaper to make that assumption unnecessary than to rely on it.
        return Str::markdown($this->markdown(), ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
