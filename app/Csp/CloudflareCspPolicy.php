<?php

namespace App\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Policies\Policy;

class CloudflareCspPolicy extends Policy
{
    public function configure()
    {
        $this
            ->addDirective(Directive::DEFAULT_SRC, ["'self'"])
            ->addDirective(Directive::SCRIPT_SRC, [
                "'self'",
                'https://static.cloudflareinsights.com',
            ])
            ->addDirective(Directive::CONNECT_SRC, [
                "'self'",
                'https://static.cloudflareinsights.com',
            ])
            ->addDirective(Directive::IMG_SRC, [
                "'self'",
                'https://static.cloudflareinsights.com',
            ])
            ->addDirective(Directive::STYLE_SRC, ["'self'"])
            ->addDirective(Directive::OBJECT_SRC, ["'none'"])
            ->addDirective(Directive::BASE_URI, ["'self'"])
            ->addDirective(Directive::FRAME_ANCESTORS, ["'none'"])
            ->addDirective(Directive::FORM_ACTION, ["'self'"]);
    }
}
