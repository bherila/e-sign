<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Page shells that ship their own light and dark themes say so: browsers draw native
 * controls to match, and the Dark Reader extension stays off instead of recolouring the
 * dark theme on top of itself.
 */
class ThemeMetaTest extends TestCase
{
    public function test_every_themed_layout_declares_its_schemes_and_locks_dark_reader(): void
    {
        $layouts = glob(resource_path('views/layouts/*.blade.php')) ?: [];
        $this->assertNotEmpty($layouts);

        foreach ($layouts as $layout) {
            $html = (string) file_get_contents($layout);
            if (! str_contains($html, '<html')) {
                continue;
            }
            $this->assertStringContainsString('<meta name="color-scheme"', $html, basename($layout));
            $this->assertStringContainsString('<meta name="darkreader-lock">', $html, basename($layout));
        }
    }
}
