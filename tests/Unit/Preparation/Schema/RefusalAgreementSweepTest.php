<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Schema;

use App\Domain\Preparation\Schema\FieldSchemaValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\StringContractSweep;

/**
 * The two implementations must refuse the same document for the same stated reason.
 *
 * The third derived sweep, and the one covering the property the other two do not: magnitude asks
 * whether a value is inside its bound, round-trip asks whether an accepted value survives being
 * stored, and this asks whether a *refusal* means the same thing on both sides. Only the verdicts
 * have ever been compared. Codes are API surface — an integration branches on them — and a message
 * is what a person reads when their document is refused, so two implementations that agree a
 * document is invalid and disagree about why are one contract in name only.
 *
 * It exists because the divergence was found by review rather than by a test: PHP reported an
 * out-of-range integer as "not an integer" while TypeScript reported the bound. That was repaired
 * by hand for one helper, which is the move this codebase has now paid for four times — fixing the
 * instance the finding named instead of the class it belongs to.
 *
 * ## How agreement is checked across two runtimes
 *
 * Through a generated artifact, `tests/Fixtures/schema/numeric-refusals.json`. This test computes
 * the refusals and asserts they match the file; `resources/js/schema/refusalAgreement.test.ts`
 * computes its own and asserts the same. Neither can drift silently: a change on either side fails
 * that side's assertion, and the file is regenerated deliberately rather than edited. It is
 * generated output, not a third hand-maintained list.
 *
 * The probes are derived from the contract — for every numeric member, one violation per
 * constraint it declares — so a member or a constraint added later is covered without anyone
 * remembering to cover it.
 */
final class RefusalAgreementSweepTest extends TestCase
{
    private const ARTIFACT = __DIR__.'/../../../Fixtures/schema/numeric-refusals.json';

    private const STRING_ARTIFACT = __DIR__.'/../../../Fixtures/schema/string-refusals.json';

    /**
     * Code points probed inside an email address: every character ECMA-262's `\s` matches, and
     * the near misses a hand-written whitespace class tends to get wrong (NEL, the Mongolian vowel
     * separator, zero-width space and joiner). The contract's pattern is ECMA-262, so the two
     * importers must draw the line in the same place, character by character.
     */
    private const EMAIL_WHITESPACE_PROBES = [
        0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20, 0x85, 0xA0, 0x1680, 0x180E, 0x2000, 0x2005, 0x200A,
        0x200B, 0x200D, 0x2028, 0x2029, 0x202F, 0x205F, 0x3000, 0xFEFF,
    ];

    public function test_every_refusal_matches_the_shared_artifact(): void
    {
        $this->assertFileExists(self::ARTIFACT, 'Run with REGENERATE_REFUSALS=1 to write it.');

        $computed = self::refusals();

        if (getenv('REGENERATE_REFUSALS') === '1') {
            file_put_contents(
                self::ARTIFACT,
                json_encode($computed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            );
        }

        /** @var array<string, array{code: string, message: string}> $expected */
        $expected = json_decode((string) file_get_contents(self::ARTIFACT), true);

        $this->assertSame(
            $expected,
            $computed,
            'A refusal changed. If that was deliberate, regenerate the artifact with '
                .'REGENERATE_REFUSALS=1 and check the TypeScript sweep still matches it — the two '
                .'implementations have to refuse the same document for the same stated reason.',
        );
    }

    /**
     * The same agreement for every string and enum member (issue #106), through its own artifact.
     */
    public function test_every_string_refusal_matches_the_shared_artifact(): void
    {
        $this->assertFileExists(self::STRING_ARTIFACT, 'Run with REGENERATE_REFUSALS=1 to write it.');

        $computed = self::stringRefusals();

        if (getenv('REGENERATE_REFUSALS') === '1') {
            file_put_contents(
                self::STRING_ARTIFACT,
                json_encode($computed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            );
        }

        /** @var array<string, array{code: string|null, message: string}> $expected */
        $expected = json_decode((string) file_get_contents(self::STRING_ARTIFACT), true);

        $this->assertSame(
            $expected,
            $computed,
            'A string refusal changed. If that was deliberate, regenerate the artifact with '
                .'REGENERATE_REFUSALS=1 and check the TypeScript sweep still matches it.',
        );
    }

    /**
     * One violation per constraint per string or enum member, plus one email per probed
     * whitespace-like code point, and what the validator says about each.
     *
     * @return array<string, array{code: string|null, message: string}>
     */
    public static function stringRefusals(): array
    {
        $refusals = [];

        foreach (StringContractSweep::members() as $path => $member) {
            foreach (StringContractSweep::violations($member) as $constraint => $value) {
                $refusals[$path.' / '.$constraint] = self::firstProblem(
                    StringContractSweep::documentWith($path, $value),
                    StringContractSweep::pointer($path),
                );
            }
        }

        foreach (self::EMAIL_WHITESPACE_PROBES as $codePoint) {
            $refusals[sprintf('recipients[].email / U+%04X', $codePoint)] = self::firstProblem(
                StringContractSweep::documentWith('recipients[].email', 'bu'.mb_chr($codePoint, 'UTF-8').'yer@example.test'),
                StringContractSweep::pointer('recipients[].email'),
            );
        }

        ksort($refusals);

        return $refusals;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{code: string|null, message: string}
     */
    private static function firstProblem(array $document, string $pointer): array
    {
        $errors = (new FieldSchemaValidator)->validate($document)->at($pointer);

        return $errors === []
            ? ['code' => null, 'message' => 'ACCEPTED']
            : ['code' => $errors[0]->code->value, 'message' => $errors[0]->message];
    }

    /**
     * One violation per constraint per numeric member, and what the validator says about it.
     *
     * @return array<string, array{code: string, message: string}>
     */
    public static function refusals(): array
    {
        $refusals = [];

        foreach (NumericBoundsSweepTest::contractMembers() as $path => $bounds) {
            foreach (self::violations($bounds) as $constraint => $value) {
                $document = NumericBoundsSweepTest::documentWith($path, $value);
                $errors = (new FieldSchemaValidator)->validate($document)->at(NumericBoundsSweepTest::pointer($path));

                $refusals[$path.' / '.$constraint] = $errors === []
                    ? ['code' => null, 'message' => 'ACCEPTED — a stated constraint that refuses nothing']
                    : ['code' => $errors[0]->code->value, 'message' => $errors[0]->message];
            }
        }

        ksort($refusals);

        return $refusals;
    }

    /**
     * The value that violates each constraint the contract states for this member.
     *
     * @param  array{type: string, minimum: int|float|null, exclusiveMinimum: int|float|null, maximum: int|float|null}  $bounds
     * @return array<string, int|float|string>
     */
    public static function violations(array $bounds): array
    {
        $step = $bounds['type'] === 'integer' ? 1 : 0.001;
        $violations = ['type' => 'not-a-number'];

        // Precision is a rule of this schema that JSON Schema cannot state — `multipleOf: 0.001`
        // is a floating-point division and ajv rejects thousands of legal three-decimal values —
        // so it is enforced by the importers and swept here rather than derived from the file.
        // A number member can be too precise; an integer one cannot.
        if ($bounds['type'] === 'number') {
            $violations['precision'] = 0.00049;
        }

        if ($bounds['minimum'] !== null) {
            $violations['minimum'] = $bounds['minimum'] - $step;
        }

        if ($bounds['exclusiveMinimum'] !== null) {
            $violations['exclusiveMinimum'] = $bounds['exclusiveMinimum'];
        }

        if ($bounds['maximum'] !== null) {
            $violations['maximum'] = $bounds['maximum'] + $step;
        }

        return $violations;
    }
}
