<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Schema;

use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\FieldSchemaValidator;
use App\Domain\Preparation\Schema\ValidationError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\StringContractSweep;

/**
 * Every string and enum member the published contract admits, probed at the edge of each
 * constraint it declares (issue #106).
 *
 * The same two properties the numeric sweeps hold, over the members they could not reach:
 *
 * 1. **Bounds.** A value at the edge of every constraint is accepted, and one past it refused:
 *    `maxLength` and `maxLength + 1`, every value of an enum and a near miss of it, a pattern's
 *    shortest satisfying value and its characteristic violations.
 * 2. **Round trip.** A value the importer accepts is stored as it was sent and still accepted
 *    after `fromArray()->toArray()`. For identifiers that is the contract's own promise —
 *    "preserved byte-for-byte across import and export".
 *
 * The third property, that both projections refuse for the same stated reason, is in
 * {@see RefusalAgreementSweepTest}. The twin of this file is
 * `resources/js/schema/stringContract.test.ts`.
 *
 * What the sweep found when it was first run, and which is why it exists: PHP's `$` matches
 * before a trailing newline, so every pattern-checked member accepted `"buyer\n"` where the
 * contract and the editor refused it; PHP's `\s` is ASCII where the contract's (ECMA-262) is
 * Unicode, so an email with a no-break space was accepted by one side only; and the email length
 * was counted in bytes by PHP and in UTF-16 units by TypeScript, where the contract counts code
 * points.
 */
final class StringContractSweepTest extends TestCase
{
    public function test_every_string_member_of_the_contract_is_swept(): void
    {
        $this->assertSame(
            self::EXPECTED_MEMBERS,
            array_keys(StringContractSweep::members()),
            'The contract gained or lost a string or enum member. Add it here and to '
                .'resources/js/schema/stringContract.test.ts — a member with no probe is a member nobody checked.',
        );
    }

    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function acceptedValues(): iterable
    {
        foreach (StringContractSweep::members() as $path => $member) {
            foreach (StringContractSweep::accepted($member) as $edge => $value) {
                yield $path.' / '.$edge => [$path, $edge, $value];
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function violatingValues(): iterable
    {
        foreach (StringContractSweep::members() as $path => $member) {
            foreach (StringContractSweep::violations($member) as $constraint => $value) {
                yield $path.' / '.$constraint => [$path, $constraint, $value];
            }
        }
    }

    #[DataProvider('acceptedValues')]
    public function test_the_member_accepts_the_edge_of_every_constraint(string $path, string $edge, mixed $value): void
    {
        $this->assertSame(
            [],
            self::problemsAt($path, StringContractSweep::documentWith($path, $value)),
            $path.' must accept '.$edge.' ('.json_encode($value, JSON_UNESCAPED_UNICODE).'): the contract admits it.',
        );
    }

    #[DataProvider('violatingValues')]
    public function test_the_member_refuses_one_step_past_every_constraint(string $path, string $constraint, mixed $value): void
    {
        $this->assertNotSame(
            [],
            self::problemsAt($path, StringContractSweep::documentWith($path, $value)),
            $path.' must refuse a value violating '.$constraint.' ('.json_encode($value, JSON_UNESCAPED_UNICODE).').',
        );
    }

    /**
     * A value accepted at an edge is stored as sent and is still acceptable once read back.
     *
     * An enum member that equals its declared default may be omitted by the canonical form
     * (`origin: top_left`, `placement: replace`); anything else must come back identical.
     */
    #[DataProvider('acceptedValues')]
    public function test_the_member_survives_its_own_round_trip(string $path, string $edge, mixed $value): void
    {
        $stored = FieldSchemaDocument::fromArray(StringContractSweep::documentWith($path, $value))->toArray();

        $this->assertSame([], self::problemsAt($path, $stored), $path.' accepts '.$edge.' but refuses what it stored.');

        $readBack = self::at($stored, StringContractSweep::pointer($path));

        if ($readBack === self::ABSENT) {
            $this->assertContains($value, self::DEFAULTS[$path] ?? [], $path.' dropped a value that is not its default.');

            return;
        }

        $this->assertSame($value, $readBack, $path.' did not store '.$edge.' byte for byte.');
    }

    private const ABSENT = "\0absent";

    /** Values the canonical form omits because the contract declares them the default. */
    private const DEFAULTS = [
        'fields[].anchor.origin' => ['top_left'],
        'fields[].anchor.placement' => ['replace'],
    ];

    /**
     * @param  array<string, mixed>  $document
     * @return list<ValidationError>
     */
    private static function problemsAt(string $path, array $document): array
    {
        return (new FieldSchemaValidator)->validate($document)->at(StringContractSweep::pointer($path));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function at(array $document, string $pointer): mixed
    {
        $node = $document;

        foreach (array_slice(explode('/', $pointer), 1) as $segment) {
            if (! is_array($node) || ! array_key_exists(is_numeric($segment) ? (int) $segment : $segment, $node)) {
                return self::ABSENT;
            }

            $node = $node[is_numeric($segment) ? (int) $segment : $segment];
        }

        return $node;
    }

    /** Asserted against the derived list, so a member cannot be added or lost silently. */
    private const EXPECTED_MEMBERS = [
        'coordinate_space.origin',
        'coordinate_space.page_box',
        'coordinate_space.page_index_base',
        'coordinate_space.rotation',
        'coordinate_space.unit',
        'document_id',
        'fields[].alias',
        'fields[].anchor.occurrence',
        'fields[].anchor.origin',
        'fields[].anchor.placement',
        'fields[].anchor.resolved.document_sha256',
        'fields[].anchor.text',
        'fields[].id',
        'fields[].label',
        'fields[].prefill.variable',
        'fields[].recipient_id',
        'fields[].type',
        'recipients[].email',
        'recipients[].id',
        'recipients[].name',
        'recipients[].role',
        'schema_version',
        'signing_order[][]',
    ];
}
