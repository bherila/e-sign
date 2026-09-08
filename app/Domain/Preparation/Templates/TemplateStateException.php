<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Templates;

use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Templates\Models\Template;
use RuntimeException;

/**
 * A template operation refused because the template, the version, or the document it names
 * is in the wrong state.
 *
 * Distinct from {@see PublishedVersionIsImmutableException}, which is the one case about a
 * row that may never change again. This one covers "not yet" and "not any more": a document
 * that has not passed preflight, a retired template, a version published twice. Every case
 * fails closed with a message written for the sender, because AGENTS.md's fail-closed rule
 * makes an unsupported operation an error and never a successful no-op.
 *
 * `$reason` is a stable machine-readable code so the HTTP adapter and the facade can branch
 * without matching on message text. It is not called `$code`: Exception already declares a
 * non-readonly `$code`, and a readonly property cannot redeclare it.
 */
final class TemplateStateException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * A version can only snapshot the review revision of a `ready` document: nothing may be
     * shown for assent that preflight did not accept, and a document that failed preflight
     * has no review revision and can never gain one.
     */
    public static function documentNotReady(Document $document): self
    {
        return new self(
            'document_not_ready',
            sprintf(
                'Document %s is %s, so it has no review revision to snapshot. Upload a PDF that passes '
                .'preflight before building a template version from it.',
                $document->public_id,
                $document->status->value,
            ),
        );
    }

    /**
     * A template may only snapshot a document from its own workspace.
     *
     * The HTTP adapter never reaches this — it resolves the document inside the workspace it
     * already resolved inside the caller's memberships, so a foreign id is a 404 there. This
     * is the domain's own guard, for callers that arrive with two models in hand.
     */
    public static function documentInAnotherWorkspace(Document $document): self
    {
        return new self(
            'document_in_another_workspace',
            sprintf(
                'Document %s belongs to a different workspace than this template. A template version '
                .'can only snapshot a document from its own workspace.',
                $document->public_id,
            ),
        );
    }

    /** A `ready` document with no review revision row is a broken intake, not a caller error. */
    public static function documentHasNoReviewRevision(Document $document): self
    {
        return new self(
            'document_has_no_review_revision',
            sprintf(
                'Document %s is marked ready but has no review revision. This is an intake fault; '
                .'the document cannot be used for a template version.',
                $document->public_id,
            ),
        );
    }

    public static function templateRetired(Template $template): self
    {
        return new self(
            'template_retired',
            sprintf(
                'Template %s is retired. Restore it before adding or publishing a version; envelopes '
                .'already sent from its versions are unaffected either way.',
                $template->public_id,
            ),
        );
    }

    public static function versionAlreadyPublished(string $versionPublicId): self
    {
        return new self(
            'version_already_published',
            sprintf('Template version %s is already published.', $versionPublicId),
        );
    }

    public static function versionNotPublished(string $versionPublicId): self
    {
        return new self(
            'version_not_published',
            sprintf(
                'Template version %s is still a draft. Only a published version can be sent, because a '
                .'draft can still change under an envelope that copied it.',
                $versionPublicId,
            ),
        );
    }

    /**
     * The stored schema no longer hashes to the digest written with it. Either the row was
     * rewritten outside TemplateService, or a database engine normalised the JSON column in
     * a way the canonical re-import does not undo. Both are faults, not caller errors.
     */
    public static function fieldSchemaDigestMismatch(string $versionPublicId): self
    {
        return new self(
            'field_schema_digest_mismatch',
            sprintf(
                'Template version %s does not match its recorded field schema digest, so it cannot be '
                .'sent. Investigate before publishing anything from this template.',
                $versionPublicId,
            ),
        );
    }

    public static function aliasAlreadyTaken(string $alias): self
    {
        return new self(
            'alias_already_taken',
            sprintf('The alias "%s" is already mapped to a template in this workspace.', $alias),
        );
    }

    public static function aliasNotFound(string $alias): self
    {
        return new self(
            'alias_not_found',
            sprintf('This template has no alias "%s".', $alias),
        );
    }
}
