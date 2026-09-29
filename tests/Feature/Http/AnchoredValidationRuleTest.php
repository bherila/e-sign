<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Requests\Signing\AcceptAgreementRequest;
use App\Http\Requests\Templates\StoreTemplateAliasRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The `regex:` validation rules refuse a value with a trailing newline (issue #135).
 *
 * Laravel's `regex` rule is a plain `preg_match()`, so without the `D` modifier a pattern's `$`
 * also matches before a final `\n`. Over HTTP the global `TrimStrings` middleware usually strips
 * the newline first, so these rules are the second line rather than the first: they hold for a
 * route that excepts an attribute from trimming, and for any caller that validates the request
 * data without the middleware stack. `Tests\Unit\Security\AnchoredPatternTest` checks the
 * spelling across `app/`; this checks that the rules as the requests declare them behave.
 */
final class AnchoredValidationRuleTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string, string, bool}>
     */
    public static function values(): array
    {
        $digest = str_repeat('a', 64);

        return [
            'reviewed material digest' => [AcceptAgreementRequest::class, 'reviewed_material_sha256', $digest, true],
            'reviewed material digest with a trailing newline' => [AcceptAgreementRequest::class, 'reviewed_material_sha256', $digest."\n", false],
            'template alias' => [StoreTemplateAliasRequest::class, 'alias', 'nda-standard', true],
            'template alias with a trailing newline' => [StoreTemplateAliasRequest::class, 'alias', "nda-standard\n", false],
        ];
    }

    /**
     * @param  class-string  $request
     */
    #[DataProvider('values')]
    public function test_the_rule_accepts_exactly_the_whole_value(string $request, string $attribute, string $value, bool $passes): void
    {
        $rules = (new $request)->rules();

        $validator = Validator::make([$attribute => $value], [$attribute => $rules[$attribute]]);

        $this->assertSame($passes, $validator->passes(), $attribute.' '.json_encode($value));
    }
}
