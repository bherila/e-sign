<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Artifacts;

use App\Domain\Preparation\Documents\DocumentStorageKey;
use InvalidArgumentException;

/**
 * The one place an artifact object key is built.
 *
 * Content-addressed and scoped by workspace and envelope, for the same three reasons
 * {@see DocumentStorageKey} gives:
 *
 *     envelopes/{workspace public id}/{envelope public id}/{kind}-{sha256}.{pdf|json}
 *
 *  1. **Nothing is ever overwritten.** Different bytes are a different key, so a write can
 *     only replace an object with itself. "Retain originals byte-for-byte" and "new artifact
 *     keys only" (AGENTS.md; docs/HANDOFF.md section 12) become properties of the layout
 *     rather than rules to remember.
 *  2. **A key is verifiable on its own.** The digest of the object's contents must equal the
 *     digest in its own name, which is exactly what the read-back check asserts before any
 *     row is written.
 *  3. **The prefix is a tenancy boundary.** Everything for one workspace sits under one
 *     prefix, so an export or the staging pruner can enumerate per workspace.
 *
 * The prefix is `envelopes/` rather than `documents/`, so a pruner that walks published
 * evidence cannot wander into uploaded originals and vice versa.
 *
 * Both identifiers are application-generated ULIDs and the digest is hex, so nothing in a key
 * comes from a filename, a title, or any other caller-supplied string.
 */
final readonly class ArtifactStorageKey
{
    /** Everything this class ever writes lives under here. Never taken from a caller. */
    public const ROOT = 'envelopes';

    private function __construct(public string $value) {}

    public static function for(
        string $workspacePublicId,
        string $envelopePublicId,
        ArtifactKind $kind,
        string $sha256,
    ): self {
        return new self(sprintf(
            '%s/%s/%s/%s-%s.%s',
            self::ROOT,
            self::segment($workspacePublicId, 'workspace public id'),
            self::segment($envelopePublicId, 'envelope public id'),
            $kind->value,
            self::digest($sha256),
            $kind->extension(),
        ));
    }

    /** The prefix holding every artifact of one envelope. */
    public static function envelopePrefix(string $workspacePublicId, string $envelopePublicId): string
    {
        return sprintf(
            '%s/%s/%s',
            self::ROOT,
            self::segment($workspacePublicId, 'workspace public id'),
            self::segment($envelopePublicId, 'envelope public id'),
        );
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function segment(string $value, string $label): string
    {
        if (preg_match('/^[0-9A-Za-z]{10,64}$/', $value) !== 1) {
            throw new InvalidArgumentException("The {$label} is not a usable storage key segment.");
        }

        return $value;
    }

    private static function digest(string $sha256): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('A storage key needs a lowercase hex SHA-256 digest.');
        }

        return $sha256;
    }
}
