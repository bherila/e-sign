<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use InvalidArgumentException;

/**
 * The `schema_version` of a field document, and the policy for accepting one.
 *
 * Policy (docs/preparation/anchors.md):
 *
 * - An additive change — a new optional property, a new field type, a new prefill variable —
 *   bumps the **minor** version. A reader of a later minor version may encounter properties it
 *   does not know, so it refuses the document rather than dropping them.
 * - Anything else — a removal, a rename, a semantic change, a new required property, a
 *   different coordinate space — bumps the **major** version.
 * - An importer refuses an unknown major version outright. It never "reads what it can".
 *
 * Both directions therefore fail closed. That is deliberate: a dropped field is a field nobody
 * was asked to sign, and a reinterpreted rectangle is a signature in the wrong place.
 */
final readonly class SchemaVersion
{
    /**
     * The version this build implements and writes.
     *
     * 1.1 adds the optional anchor members `placement`, `required` and `tolerance`, and the
     * service-written `resolved` receipt (docs/preparation/anchors.md). They are additive and
     * optional, which is exactly the case the policy above says bumps the minor version: a 1.0
     * reader validating with `additionalProperties: false` must not be handed a document that
     * still calls itself 1.0 and carries members its contract does not declare.
     *
     * A document keeps the version it arrived with. A 1.0 document that uses none of the new
     * members stays a 1.0 document, byte for byte and digest for digest; only a document this
     * service *rewrites* — which in practice means one whose anchors it resolved — is stamped
     * with the version it was written as.
     */
    public const CURRENT = '1.1';

    public const MAJOR = 1;

    public const MINOR = 1;

    /** Every minor this build can read, oldest first. Each has its own published contract file. */
    public const SUPPORTED = ['1.0', '1.1'];

    public function __construct(public int $major, public int $minor)
    {
        if ($major < 0 || $minor < 0) {
            throw new InvalidArgumentException('Schema version components must not be negative.');
        }
    }

    public static function current(): self
    {
        return new self(self::MAJOR, self::MINOR);
    }

    /**
     * Parse a `MAJOR.MINOR` string. Returns null for anything else, including `1`, `1.0.0`,
     * `v1.0`, and `01.0`; the version string is an exact token, not a loose number.
     */
    public static function parse(string $version): ?self
    {
        if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $version, $matches) !== 1) {
            return null;
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    /** Whether this build can read the document at all. */
    public function isSupportedMajor(): bool
    {
        return $this->major === self::MAJOR;
    }

    /** Whether this build implements every property the document may legally contain. */
    public function isSupported(): bool
    {
        return $this->isSupportedMajor() && $this->minor <= self::MINOR;
    }

    public function toString(): string
    {
        return $this->major.'.'.$this->minor;
    }
}
