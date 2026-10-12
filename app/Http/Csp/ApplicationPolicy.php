<?php

namespace App\Http\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

/**
 * The Content Security Policy for the application's ordinary pages.
 *
 * `config/csp.php` names this class instead of listing directives, because the
 * directives are enum cases and a cached configuration has to hold plain data
 * only: an enum case serializes into `bootstrap/cache/config.php` as executable
 * code, which the deployment's cached-configuration audit refuses. The reasoning
 * for each directive is in `config/csp.php`.
 */
final class ApplicationPolicy implements Preset
{
    public function configure(Policy $policy): void
    {
        $policy
            ->add(Directive::DEFAULT, [Keyword::SELF])
            ->add(Directive::SCRIPT, [Keyword::SELF])
            ->add(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE])
            ->add(Directive::IMG, [Keyword::SELF, 'data:', 'blob:'])
            ->add(Directive::FONT, [Keyword::SELF, 'data:'])
            ->add(Directive::CONNECT, [Keyword::SELF])
            ->add(Directive::WORKER, [Keyword::SELF, 'blob:'])
            ->add(Directive::OBJECT, [Keyword::NONE])
            ->add(Directive::BASE, [Keyword::NONE])
            ->add(Directive::FRAME_ANCESTORS, [Keyword::NONE])
            ->add(Directive::FORM_ACTION, [Keyword::SELF]);
    }
}
