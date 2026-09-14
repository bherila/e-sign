<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Isolation;

use App\Domain\Preparation\Assembly\AssembledDocument;
use App\Domain\Preparation\Assembly\OverlayImage;
use App\Domain\Preparation\Assembly\OverlayRectangle;
use App\Domain\Preparation\Assembly\OverlayText;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Geometry\PageBox;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Geometry\PageRotation;
use App\Domain\Preparation\Preflight\DocumentMetrics;
use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightFinding;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\Preflight\PreflightReport;
use App\Domain\Preparation\Preflight\PreflightSeverity;
use App\Domain\Preparation\Text\TextDirection;
use App\Domain\Preparation\Text\TextRun;
use UnexpectedValueException;

/**
 * The messages a parent and a child document read exchange.
 *
 * Native serialization, because both sides are this codebase and the values are this domain's own
 * value objects — and `unserialize()` against an explicit class allowlist in each direction, never
 * an open one. A class outside the list becomes an incomplete object, which is refused rather than
 * passed on: the child reads hostile bytes, and nothing it writes back gets to name a class to
 * instantiate here.
 */
final class DocumentReadCodec
{
    /** What a request may carry from the parent to the child. */
    public const REQUEST_CLASSES = [
        PreflightLimits::class,
        OverlayRectangle::class,
        OverlayText::class,
        OverlayImage::class,
        NativeRect::class,
    ];

    /** What a response may carry from the child to the parent. */
    public const RESPONSE_CLASSES = [
        PreflightReport::class,
        PreflightFinding::class,
        PreflightCode::class,
        PreflightSeverity::class,
        DocumentMetrics::class,
        PageGeometry::class,
        PageBox::class,
        PageRotation::class,
        TextRun::class,
        NativeRect::class,
        TextDirection::class,
        AssembledDocument::class,
    ];

    /** @param array<string, mixed> $message */
    public static function encode(array $message): string
    {
        return serialize($message);
    }

    /**
     * @param  list<class-string>  $allowedClasses
     * @return array<string, mixed>
     *
     * @throws UnexpectedValueException When the payload is not a message, or carries a class outside the list.
     */
    public static function decode(string $payload, array $allowedClasses): array
    {
        $message = @unserialize($payload, ['allowed_classes' => $allowedClasses]);

        if (! is_array($message)) {
            throw new UnexpectedValueException('A document read message could not be decoded.');
        }

        self::refuseIncomplete($message);

        /** @var array<string, mixed> $message */
        return $message;
    }

    private static function refuseIncomplete(mixed $value): void
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            throw new UnexpectedValueException('A document read message carried a class it is not allowed to.');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::refuseIncomplete($item);
            }

            return;
        }

        if (is_object($value) && ! $value instanceof \UnitEnum) {
            foreach ((array) $value as $property) {
                self::refuseIncomplete($property);
            }
        }
    }
}
