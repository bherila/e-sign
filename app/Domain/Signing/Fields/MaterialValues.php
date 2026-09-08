<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeFieldValue;

/**
 * The digest a recipient's assent is bound to.
 *
 * "The material values" means every value that is part of the shared agreement content —
 * everything {@see FieldMateriality} does not classify as belonging to one signer. Two
 * signers must be able to prove they agreed to the same text, so a later signer's signature
 * image must not change the digest the earlier signer accepted, and a change to a shared
 * term must.
 *
 * The encoding is versioned and canonical, as docs/HANDOFF.md section 8 requires:
 *
 * ```json
 * {"encoding":"esign.material-values.v1","values":[{"field":"agreement_effective_date","value":"2026-01-31"}]}
 * ```
 *
 * Ordering is by field id in byte order, not schema order, so re-ordering fields in a schema
 * that is otherwise identical cannot change the digest. Fields with no value are absent
 * rather than present as null: "not filled in" and "filled in with nothing" are the same
 * fact and must not produce two digests, which is also why
 * {@see FieldValueValidator} refuses an empty string outright.
 *
 * An envelope with no material values at all has a well-defined digest — the digest of the
 * empty list — rather than a sentinel. There is nothing special about an agreement whose
 * text has no blanks in it.
 */
final class MaterialValues
{
    /** Bumping this changes every future digest, so it is a documented evidence-format change. */
    public const ENCODING = 'esign.material-values.v1';

    /**
     * @param  iterable<EnvelopeFieldValue>  $values
     */
    public static function canonicalJson(FieldSchemaDocument $schema, iterable $values): string
    {
        $material = [];

        foreach ($values as $value) {
            $field = $schema->field($value->schema_field_id);

            // A stored value for a field the schema does not declare cannot be material to
            // an agreement written against that schema, and silently hashing it would make
            // the digest depend on rows nobody can see in the document.
            if ($field === null || ! FieldMateriality::isMaterial($field->type)) {
                continue;
            }

            $material[$field->id] = $value->value;
        }

        ksort($material, SORT_STRING);

        return CanonicalValue::encode([
            'encoding' => self::ENCODING,
            'values' => array_values(array_map(
                static fn (string $id): array => ['field' => $id, 'value' => $material[$id]],
                array_keys($material),
            )),
        ]);
    }

    /**
     * @param  iterable<EnvelopeFieldValue>  $values
     */
    public static function digest(FieldSchemaDocument $schema, iterable $values): string
    {
        return hash('sha256', self::canonicalJson($schema, $values));
    }

    /**
     * The digest of an envelope as it stands right now.
     *
     * Reads the values through the query builder rather than a loaded relation, so a caller
     * holding a stale relation cannot compute a digest for content the database no longer
     * holds. Inside a transition this runs under the same transaction as the change.
     */
    public static function forEnvelope(Envelope $envelope): string
    {
        return self::digest(
            $envelope->fieldSchema(),
            EnvelopeFieldValue::query()
                ->where('envelope_id', $envelope->getKey())
                ->orderBy('schema_field_id')
                ->get(),
        );
    }
}
