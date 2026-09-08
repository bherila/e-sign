<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Preparation\Templates\RenderSettings;
use Illuminate\Validation\Rule;

/**
 * The parts of a version payload that drafting and editing have in common.
 *
 * Two layers of validation, and the split is deliberate:
 *
 *  - **Here**: the request *shape*. Is `field_schema` an object at all, is
 *    `render_settings` restricted to the declared keys, is `consent_policy_version` a
 *    plausible string. These produce ordinary Laravel 422s with a field name a form can
 *    attach a message to.
 *  - **In the domain**: what the field set *means*. Duplicate ids, fields bound to
 *    recipients that do not exist, rectangles off the edge of a page. Those come from
 *    App\Domain\Preparation\Schema\FieldSchemaValidator, which is the single structural
 *    authority, and the controller surfaces its whole error list with JSON Pointers.
 *
 * Re-implementing the second layer as Laravel rules would create a second error vocabulary
 * the editor cannot use; see the "Why no JSON Schema library on the server" section of
 * docs/preparation/field-schema.md, which reaches the same conclusion for the same reason.
 *
 * `render_settings` uses Laravel's `array:` rule to *restrict* the permitted keys, so an
 * unrecognised setting is a 422 rather than a value that is stored and ignored. The
 * accessors below re-cast the values, because `boolean` accepts `"1"` from a form body while
 * RenderSettings requires a real bool — reading the input verbatim would silently drop a
 * setting the sender did state.
 */
abstract class TemplateVersionContentRequest extends WorkspaceTemplateRequest
{
    protected function permission(): WorkspacePermission
    {
        return WorkspacePermission::CreateTemplates;
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentRules(): array
    {
        return [
            'consent_policy_version' => ['sometimes', 'nullable', 'string', 'max:64'],

            'render_settings' => [
                'sometimes',
                'array:date_format,timezone,include_certificate_page,signature_appearance',
            ],
            'render_settings.date_format' => ['sometimes', Rule::in(RenderSettings::DATE_FORMATS)],
            'render_settings.timezone' => ['sometimes', 'timezone'],
            'render_settings.include_certificate_page' => ['sometimes', 'boolean'],
            'render_settings.signature_appearance' => [
                'sometimes',
                Rule::in(RenderSettings::SIGNATURE_APPEARANCES),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentMessages(): array
    {
        return [
            'field_schema.array' => 'Send the native field schema document as a JSON object.',
            'render_settings.array' => 'Unrecognised render setting. Render settings are a declared '
                .'capability: an unknown one is refused rather than stored and ignored.',
        ];
    }

    /**
     * The submitted field schema document, or null when the caller sent none.
     *
     * @return array<string, mixed>|null
     */
    public function fieldSchema(): ?array
    {
        $schema = $this->input('field_schema');

        return is_array($schema) ? $schema : null;
    }

    public function consentPolicyVersion(): ?string
    {
        $version = $this->input('consent_policy_version');

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }

    /**
     * The submitted render settings with their types normalised, or null when the caller
     * sent none. Only the keys actually present are returned, so RenderSettings applies its
     * own defaults to the rest.
     *
     * @return array<string, mixed>|null
     */
    public function renderSettings(): ?array
    {
        $settings = $this->input('render_settings');

        if (! is_array($settings)) {
            return null;
        }

        $normalized = [];

        foreach (['date_format', 'timezone', 'signature_appearance'] as $key) {
            if (array_key_exists($key, $settings)) {
                $normalized[$key] = (string) $settings[$key];
            }
        }

        if (array_key_exists('include_certificate_page', $settings)) {
            $normalized['include_certificate_page'] = $this->boolean('render_settings.include_certificate_page');
        }

        return $normalized;
    }
}
