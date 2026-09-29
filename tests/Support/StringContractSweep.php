<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Preparation\Schema\SchemaVersion;
use LogicException;

/**
 * Shared machinery for the three contract sweeps over **string and enum members** (issue #106).
 *
 * The numeric sweeps (`NumericBoundsSweepTest`, `RefusalAgreementSweepTest`) walk
 * `field-schema-1.1.json` for every number; this walks it for every member whose constraint is
 * about a string — `pattern`, `minLength`, `maxLength` — or about a closed set of values — `enum`
 * and `const`. The twin is `resources/js/schema/stringSweep.support.ts`: same walk, same document
 * builder, same probes, byte for byte, so the two projections are asked identical questions.
 *
 * Three things are derived rather than listed, each so that an omission fails instead of passing:
 *
 * - **The members** come from the contract file. A string member added to the schema is swept
 *   the moment it exists.
 * - **The values** that satisfy or violate a `pattern` come from {@see self::PATTERNS}, keyed by
 *   the pattern text itself. A pattern the contract gains without an entry here throws, so a new
 *   pattern cannot be swept with nothing.
 * - **The edges** come from the constraints: a `maxLength` is probed at exactly `maxLength` and at
 *   one past it, never at a comfortable distance.
 *
 * Lengths are probed with astral characters wherever the pattern admits them. JSON Schema counts
 * `maxLength` in code points; PHP's `strlen()` counts bytes and JavaScript's `length` counts UTF-16
 * units, so an ASCII-only probe agrees with all three and proves nothing about which one is used.
 */
final class StringContractSweep
{
    /** A character outside the BMP: one code point, four UTF-8 bytes, two UTF-16 units. */
    public const ASTRAL = "\u{1F600}";

    public const ANCHORED_FIELD = 5;

    public const PREFILLED_FIELD = 2;

    public const PLAIN_FIELD = 0;

    /** The recipient whose id is renamed everywhere it is referenced when an identifier is probed. */
    public const PROBED_RECIPIENT = 'buyer';

    /**
     * Every pattern the contract states, with how to satisfy it at a given length and how to
     * violate it. Keyed by the pattern exactly as the contract spells it.
     */
    private const PATTERNS = [
        '^[A-Za-z0-9][A-Za-z0-9._-]*$' => 'identifier',
        '^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)*$' => 'variable',
        '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$' => 'email',
        '^[0-9a-f]{64}$' => 'sha256',
    ];

    /**
     * Every string or enum member of the contract, as a document path, with its constraints.
     *
     * @return array<string, array{type: string|null, minLength: int|null, maxLength: int|null, pattern: string|null, enum: list<mixed>|null}>
     */
    public static function members(string $version = SchemaVersion::CURRENT): array
    {
        $schema = FieldSchemaFixture::schema($version);
        $defs = $schema['$defs'];
        $found = [];

        $walk = function (array $node, string $path, array $seen) use (&$walk, $defs, &$found): void {
            if (isset($node['$ref'])) {
                $name = substr((string) $node['$ref'], strlen('#/$defs/'));

                if (! in_array($name, $seen, true)) {
                    $walk($defs[$name], $path, [...$seen, $name]);
                }

                return;
            }

            foreach (['oneOf', 'anyOf', 'allOf'] as $combinator) {
                foreach ($node[$combinator] ?? [] as $branch) {
                    $walk($branch, $path, $seen);
                }
            }

            $type = $node['type'] ?? null;
            $enum = array_key_exists('const', $node) ? [$node['const']] : ($node['enum'] ?? null);

            // Fail closed on every shape this walk does not understand, rather than dropping a
            // member the coverage list could then never notice was missing.
            if (is_array($type)) {
                throw new LogicException($path.' declares a union type, which the string sweep cannot probe.');
            }

            if ($type !== 'string' && array_intersect(['pattern', 'minLength', 'maxLength', 'format'], array_keys($node)) !== []) {
                throw new LogicException($path.' states a string constraint without type string.');
            }

            if (is_array($node['additionalProperties'] ?? null) || isset($node['patternProperties'])) {
                throw new LogicException($path.' admits members by schema rather than by name; the sweep cannot address them.');
            }

            if (($type === 'string' || $enum !== null) && isset($found[$path])) {
                throw new LogicException($path.' is declared by two branches; the sweep would probe only one.');
            }

            if ($type === 'string' || $enum !== null) {
                $found[$path] = [
                    'type' => is_string($type) ? $type : null,
                    'minLength' => $node['minLength'] ?? null,
                    'maxLength' => $node['maxLength'] ?? null,
                    'pattern' => $node['pattern'] ?? null,
                    'enum' => $enum,
                ];

                return;
            }

            if ($type === 'array' && isset($node['items'])) {
                $walk($node['items'], $path.'[]', $seen);

                return;
            }

            foreach ($node['properties'] ?? [] as $key => $child) {
                $walk($child, $path === '' ? (string) $key : $path.'.'.$key, $seen);
            }
        };

        $walk($schema, '', []);
        ksort($found);

        return $found;
    }

    /**
     * Values the contract says this member accepts, at the edges of its constraints.
     *
     * @param  array{type: string|null, minLength: int|null, maxLength: int|null, pattern: string|null, enum: list<mixed>|null}  $member
     * @return array<string, mixed>
     */
    public static function accepted(array $member): array
    {
        if ($member['enum'] !== null) {
            $values = [];

            foreach ($member['enum'] as $value) {
                $values['enum '.json_encode($value)] = $value;
            }

            return $values;
        }

        $values = ['shortest' => self::satisfying($member, self::shortestLength($member))];

        if ($member['maxLength'] !== null) {
            $values['maxLength'] = self::satisfying($member, $member['maxLength']);
        }

        return $values;
    }

    /**
     * One value per constraint this member declares, violating exactly that constraint.
     *
     * @param  array{type: string|null, minLength: int|null, maxLength: int|null, pattern: string|null, enum: list<mixed>|null}  $member
     * @return array<string, mixed>
     */
    public static function violations(array $member): array
    {
        // A boolean is not a string, a number or an integer, so it is the wrong type for every
        // member here, including `anchor.occurrence`, whose other branch accepts an integer.
        $violations = ['type' => true];

        if ($member['enum'] !== null) {
            $first = $member['enum'][0];

            if (is_string($first)) {
                $swapped = strtoupper($first) === $first ? strtolower($first) : strtoupper($first);

                if ($swapped !== $first) {
                    $violations['enum / case'] = $swapped;
                }

                $violations['enum / trailing newline'] = $first."\n";
            } else {
                $violations['enum / other'] = is_int($first) ? $first + 1 : null;
            }

            // Values the two runtimes decode or spell differently, so a refusal that quotes what was
            // declared cannot just print it: `{}` is an array in PHP, and 1e20 is `1.0e+20` there.
            $violations['type / object'] = [];
            $violations['type / large number'] = 1e20;
            $violations['type / fraction'] = 1.5;

            return $violations;
        }

        if ($member['minLength'] !== null && $member['minLength'] > 0) {
            $violations['minLength'] = str_repeat('a', $member['minLength'] - 1);
        }

        if ($member['maxLength'] !== null) {
            $violations['maxLength'] = self::satisfying($member, $member['maxLength'] + 1);
        }

        if ($member['pattern'] !== null) {
            foreach (self::patternViolations($member['pattern']) as $name => $value) {
                $violations['pattern / '.$name] = $value;
            }
        }

        return $violations;
    }

    /**
     * A value of exactly `$length` code points that satisfies the member's pattern, if any.
     *
     * @param  array{type: string|null, minLength: int|null, maxLength: int|null, pattern: string|null, enum: list<mixed>|null}  $member
     */
    public static function satisfying(array $member, int $length): string
    {
        return match (self::patternKind($member['pattern'])) {
            // Astral wherever the pattern admits it, so a length is counted in code points.
            null => str_repeat(self::ASTRAL, $length),
            'identifier' => 'a'.substr(str_repeat('9._-Z', $length), 0, $length - 1),
            'variable' => $length < 3 ? str_repeat('a', $length) : 'a.b'.str_repeat('_', $length - 3),
            'email' => $length < 14
                ? 'a@'.str_repeat('b', max(1, $length - 4)).'.c'
                : str_repeat(self::ASTRAL, $length - 13).'@example.test',
            'sha256' => str_repeat('d', $length),
        };
    }

    /**
     * @param  array{type: string|null, minLength: int|null, maxLength: int|null, pattern: string|null, enum: list<mixed>|null}  $member
     */
    private static function shortestLength(array $member): int
    {
        return match (self::patternKind($member['pattern'])) {
            'email' => max(5, $member['minLength'] ?? 0),
            'sha256' => 64,
            default => max(1, $member['minLength'] ?? 0),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function patternViolations(string $pattern): array
    {
        return match (self::patternKind($pattern)) {
            'identifier' => ['leading punctuation' => '-a', 'trailing newline' => "a\n"],
            'variable' => ['upper case' => 'Recipient.name', 'trailing newline' => "recipient.name\n"],
            'email' => [
                'no domain dot' => 'buyer@example',
                'unicode whitespace' => "bu\u{00A0}yer@example.test",
                'trailing newline' => "buyer@example.test\n",
            ],
            'sha256' => ['upper case' => str_repeat('D', 64), 'trailing newline' => str_repeat('d', 64)."\n"],
        };
    }

    private static function patternKind(?string $pattern): ?string
    {
        if ($pattern === null) {
            return null;
        }

        return self::PATTERNS[$pattern] ?? throw new LogicException(
            'The contract states a pattern the string sweep has no probes for: '.$pattern.'. Add it to '
                .self::class.'::PATTERNS and to resources/js/schema/stringSweep.support.ts.',
        );
    }

    /**
     * A document carrying `$value` at `$path`, relaxed so that nothing but the member under test
     * can answer at its pointer.
     *
     * An identifier that other members refer to is renamed everywhere it is referenced, because a
     * recipient id that nothing in `signing_order` names is refused as an unknown recipient before
     * its own format could be judged.
     *
     * @return array<string, mixed>
     */
    public static function documentWith(string $path, mixed $value, string $version = SchemaVersion::CURRENT): array
    {
        $document = FieldSchemaFixture::asArray();
        $document['schema_version'] = $version;

        if (in_array($path, ['recipients[].id', 'fields[].recipient_id', 'signing_order[][]'], true)) {
            $document['recipients'][0]['id'] = $value;

            foreach ($document['signing_order'] as $stage => $members) {
                foreach ($members as $position => $member) {
                    if ($member === self::PROBED_RECIPIENT) {
                        $document['signing_order'][$stage][$position] = $value;
                    }
                }
            }

            foreach ($document['fields'] as $index => $field) {
                if ($field['recipient_id'] === self::PROBED_RECIPIENT) {
                    $document['fields'][$index]['recipient_id'] = $value;
                }
            }

            return $document;
        }

        if ($path === 'schema_version') {
            $document['schema_version'] = $value;

            return $document;
        }

        if (str_starts_with($path, 'fields[].anchor.resolved')) {
            $field = &$document['fields'][self::ANCHORED_FIELD];
            $field['anchor']['resolved'] = [
                'document_sha256' => str_repeat('d', 64),
                'page' => $field['page'],
                'occurrence_index' => 1,
                'anchor_rect' => ['x' => 330, 'y' => 622.4, 'width' => 165.6, 'height' => 12],
                'rect' => $field['rect'],
            ];
            unset($field);
        }

        $target = &$document;

        foreach (self::segments($path) as $depth => $segment) {
            if ($depth === count(self::segments($path)) - 1) {
                $target[$segment] = $value;

                break;
            }

            $target = &$target[$segment];
        }

        return $document;
    }

    /** RFC 6901 pointer of the probed member in {@see self::documentWith()}'s document. */
    public static function pointer(string $path): string
    {
        return '/'.implode('/', self::segments($path));
    }

    /**
     * @return list<string|int>
     */
    private static function segments(string $path): array
    {
        $index = match (true) {
            str_starts_with($path, 'fields[].anchor') => self::ANCHORED_FIELD,
            str_starts_with($path, 'fields[].prefill') => self::PREFILLED_FIELD,
            default => self::PLAIN_FIELD,
        };

        $segments = [];

        foreach (explode('.', $path) as $key) {
            if ($key === 'signing_order[][]') {
                array_push($segments, 'signing_order', 0, 0);
            } elseif (str_ends_with($key, '[]')) {
                array_push($segments, substr($key, 0, -2), $key === 'fields[]' ? $index : 0);
            } else {
                $segments[] = $key;
            }
        }

        return $segments;
    }
}
