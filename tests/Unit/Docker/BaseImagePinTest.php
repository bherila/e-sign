<?php

declare(strict_types=1);

namespace Tests\Unit\Docker;

use PHPUnit\Framework\TestCase;

/**
 * Every external base image of the production Dockerfile is pinned by digest (issue #37).
 *
 * A tag such as `php:8.5-fpm` is re-pointed upstream without notice, so two builds of one commit
 * can start from different bytes. A `tag@sha256:…` reference cannot. A stage that builds on an
 * earlier stage (`FROM base`) names no registry image and needs no pin.
 */
final class BaseImagePinTest extends TestCase
{
    public function test_every_external_base_image_is_pinned_by_digest(): void
    {
        $dockerfile = (string) file_get_contents(__DIR__.'/../../../.docker/Dockerfile');
        preg_match_all('/^FROM(?:\s+--platform=\S+)?\s+(\S+)(?:\s+AS\s+(\S+))?/mi', $dockerfile, $matches, PREG_SET_ORDER);

        $stages = [];
        $unpinned = [];
        $external = 0;

        foreach ($matches as $match) {
            $image = $match[1];

            if (! in_array($image, $stages, true)) {
                $external++;

                if (preg_match('/^[^@\s]+:[^@\s]+@sha256:[0-9a-f]{64}$/D', $image) !== 1) {
                    $unpinned[] = $image;
                }
            }

            if (isset($match[2])) {
                $stages[] = $match[2];
            }
        }

        $this->assertGreaterThan(0, $external, 'No external base image found; the pattern no longer reads the Dockerfile.');
        $this->assertSame([], $unpinned, 'Pin these as tag@sha256:<index digest> (scripts/refresh-base-image-digests.sh).');
    }
}
