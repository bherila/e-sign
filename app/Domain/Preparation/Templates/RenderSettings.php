<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Templates;

use DateTimeZone;
use InvalidArgumentException;

/**
 * The rendering decisions a template version freezes.
 *
 * docs/HANDOFF.md section 6 requires a version to snapshot "relevant rendering settings"
 * along with the revision, recipients, fields, and consent version. These are the ones that
 * change what a signer reads or what the sealed PDF contains, so they cannot be read from
 * live configuration at send time: a deployment that switched its date format would
 * otherwise make two envelopes from the same version render different dates, and the
 * evidence would not say which one a signer saw.
 *
 * **The property list is a declared capability, not a free-form bag.** An unrecognised key
 * is rejected, exactly as an unrecognised field type is (docs/preparation/field-schema.md),
 * because a silently ignored render setting is a sender who believes they turned something
 * on. Adding one means a case here, a rule in
 * App\Http\Requests\Templates\StoreTemplateVersionRequest, and a row in
 * docs/preparation/templates.md.
 *
 * Immutable, and canonical on the way out: `toArray()` writes every property in a fixed
 * order with defaults stated, so two versions with the same settings store the same JSON.
 */
final readonly class RenderSettings
{
    /** How a date field is written into the PDF. */
    public const DATE_FORMATS = ['iso', 'us', 'eu', 'long'];

    /** What a signer may use to produce a signature mark. */
    public const SIGNATURE_APPEARANCES = ['drawn', 'typed', 'either'];

    public const DEFAULT_DATE_FORMAT = 'iso';

    public const DEFAULT_TIMEZONE = 'UTC';

    public const DEFAULT_INCLUDE_CERTIFICATE_PAGE = true;

    public const DEFAULT_SIGNATURE_APPEARANCE = 'either';

    public function __construct(
        public string $dateFormat = self::DEFAULT_DATE_FORMAT,
        public string $timezone = self::DEFAULT_TIMEZONE,
        public bool $includeCertificatePage = self::DEFAULT_INCLUDE_CERTIFICATE_PAGE,
        public string $signatureAppearance = self::DEFAULT_SIGNATURE_APPEARANCE,
    ) {
        if (! in_array($dateFormat, self::DATE_FORMATS, true)) {
            throw new InvalidArgumentException(
                'Unsupported date format "'.$dateFormat.'"; expected one of '.implode(', ', self::DATE_FORMATS).'.',
            );
        }

        if (! in_array($signatureAppearance, self::SIGNATURE_APPEARANCES, true)) {
            throw new InvalidArgumentException(
                'Unsupported signature appearance "'.$signatureAppearance.'"; expected one of '
                .implode(', ', self::SIGNATURE_APPEARANCES).'.',
            );
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(
                'Unsupported timezone "'.$timezone.'"; expected an IANA identifier such as America/New_York.',
            );
        }
    }

    /** The settings a version gets when the caller states none. */
    public static function defaults(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws InvalidArgumentException On an unknown key or an unsupported value. HTTP
     *                                  callers never reach this: the Form Request states the
     *                                  same rules and answers 422.
     */
    public static function fromArray(array $settings): self
    {
        $unknown = array_diff(array_keys($settings), [
            'date_format',
            'timezone',
            'include_certificate_page',
            'signature_appearance',
        ]);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown render setting(s): '.implode(', ', $unknown).'. Render settings are a declared '
                .'capability; an unrecognised one is refused rather than stored and ignored.',
            );
        }

        $defaults = self::defaults();

        return new self(
            is_string($settings['date_format'] ?? null) ? $settings['date_format'] : $defaults->dateFormat,
            is_string($settings['timezone'] ?? null) ? $settings['timezone'] : $defaults->timezone,
            is_bool($settings['include_certificate_page'] ?? null)
                ? $settings['include_certificate_page']
                : $defaults->includeCertificatePage,
            is_string($settings['signature_appearance'] ?? null)
                ? $settings['signature_appearance']
                : $defaults->signatureAppearance,
        );
    }

    /**
     * Canonical form: fixed key order, every property stated.
     *
     * @return array{date_format: string, timezone: string, include_certificate_page: bool, signature_appearance: string}
     */
    public function toArray(): array
    {
        return [
            'date_format' => $this->dateFormat,
            'timezone' => $this->timezone,
            'include_certificate_page' => $this->includeCertificatePage,
            'signature_appearance' => $this->signatureAppearance,
        ];
    }
}
