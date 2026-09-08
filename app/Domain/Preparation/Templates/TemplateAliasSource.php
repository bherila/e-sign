<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Templates;

/**
 * Whose namespace a template alias belongs to.
 *
 * The value is stored in `template_aliases.source`, so renaming a case rewrites history.
 * A new source is an additive change here plus a line in docs/preparation/templates.md;
 * an alias whose source is not in this list is rejected, never stored as free text, because
 * "which provider does this id come from" is the question a migration audit has to answer.
 */
enum TemplateAliasSource: string
{
    /**
     * A template id from the provider being migrated away from (docs/HANDOFF.md section 2:
     * the consumer hardcodes Firma template ids). Kept strictly separate from the native
     * `templates.public_id`, which is never derived from one of these.
     */
    case ImportedProvider = 'imported-provider';

    /**
     * A stable handle an operator chose, so integration code can address a template by a
     * name it controls instead of by a ULID it has to look up.
     */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::ImportedProvider => 'Imported provider template id',
            self::Manual => 'Operator-assigned handle',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
