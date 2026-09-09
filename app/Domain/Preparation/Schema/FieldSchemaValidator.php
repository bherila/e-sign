<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use App\Domain\Preparation\Text\AnchorOrigin;

/**
 * Validates a decoded native field document against field schema 1.0.
 *
 * This is the single structural authority: {@see FieldSchemaDocument::fromArray()} runs it and
 * refuses to build anything it rejects, so there is no second, looser parser. It collects every
 * problem in document order — the editor annotates all bad fields in one pass — and each one
 * carries a JSON Pointer, a stable {@see ValidationCode}, and a human message.
 *
 * ## Why not a JSON Schema library
 *
 * `resources/schema/field-schema-1.0.json` is the published contract, and it is enforced: the
 * TypeScript suite validates the shared fixture against that exact file with `ajv`, and a PHP
 * test pins the schema file's enums, property lists, and constants against these classes, so the
 * two cannot drift. What is not done is *re-implementing* the checks below on top of a PHP JSON
 * Schema evaluator. Every interesting rule here is one JSON Schema cannot express — an id that
 * must be unique, a `recipient_id` that must resolve, a recipient that must appear in exactly one
 * signing stage, a rectangle that must fit a page whose size lives in the PDF, a prefill variable
 * that must resolve against the sending context — so the library would add a production
 * dependency, a second error vocabulary, and pointer-shaped messages the editor cannot use, in
 * exchange for the least interesting third of the work. THIRD_PARTY_NOTICES.md records the same
 * conclusion for `opis/json-schema` arriving as a transitive dependency.
 *
 * ## Checks that need context
 *
 * A field document names a `document_id` and 1-based page numbers; it carries no page geometry
 * and no variable set. So:
 *
 * - `page >= 1` is always checked. The page *count* is only checked when `$pageSizes` is given.
 * - A rectangle is only checked against the page edge for pages `$pageSizes` describes.
 * - A prefill variable is only checked for resolvability when `$variables` is given.
 *
 * Callers that have the PDF (the send-time gate) must pass both. Callers that do not (a draft
 * save from the editor) get every context-free rule and a document that is structurally sound.
 * The omitted checks are never silently reported as passed.
 */
final class FieldSchemaValidator
{
    /** Stable, caller-chosen id. Preserved verbatim, so the character set is narrow on purpose. */
    public const IDENTIFIER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    public const IDENTIFIER_MAX_LENGTH = 64;

    /** Dotted lower-snake path, for example `recipient.name`. */
    public const VARIABLE_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    public const VARIABLE_MAX_LENGTH = 128;

    /**
     * Deliberately coarse: one `@`, a dot in the domain, no whitespace. Deliverability is
     * decided by the mail transport, not by a regular expression, and a stricter pattern here
     * would reject addresses that work.
     */
    public const EMAIL_PATTERN = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';

    public const EMAIL_MAX_LENGTH = 320;

    public const NAME_MAX_LENGTH = 255;

    public const ROLE_MAX_LENGTH = 128;

    public const LABEL_MAX_LENGTH = 200;

    public const ANCHOR_TEXT_MAX_LENGTH = 255;

    /** @var list<string> */
    public const DOCUMENT_REQUIRED = [
        'schema_version',
        'document_id',
        'coordinate_space',
        'recipients',
        'signing_order',
        'fields',
    ];

    /** @var list<string> */
    public const RECIPIENT_REQUIRED = ['id', 'name', 'email'];

    /** @var list<string> */
    public const RECIPIENT_OPTIONAL = ['role'];

    /** @var list<string> */
    public const FIELD_REQUIRED = ['id', 'recipient_id', 'type', 'page', 'rect'];

    /** @var list<string> */
    public const FIELD_OPTIONAL = ['required', 'read_only', 'label', 'alias', 'prefill', 'anchor'];

    /** @var list<string> */
    public const RECT_REQUIRED = ['x', 'y', 'width', 'height'];

    /** @var list<string> */
    public const ANCHOR_REQUIRED = ['text', 'occurrence'];

    /** @var list<string> */
    public const ANCHOR_OPTIONAL = ['placement', 'origin', 'offset', 'required', 'tolerance', 'resolved'];

    /** @var list<string> */
    public const RESOLVED_ANCHOR_REQUIRED = ['document_sha256', 'page', 'occurrence_index', 'anchor_rect', 'rect'];

    /**
     * Anchor members that arrived after 1.0, and the minor that declares them.
     *
     * @var list<string>
     */
    public const ANCHOR_MEMBERS_SINCE_1_1 = ['placement', 'required', 'tolerance', 'resolved'];

    public const ANCHOR_MEMBERS_MINOR = 1;

    private const MAJOR_MINOR_1_1 = '1.1';

    /**
     * @param  array<string, mixed>  $document  A decoded document (`json_decode(..., true)`).
     * @param  PageSizes|null  $pageSizes  Displayed page sizes of the target PDF, when known.
     * @param  list<string>|null  $variables  Prefill variables the sending context can resolve.
     */
    public function validate(array $document, ?PageSizes $pageSizes = null, ?array $variables = null): ValidationResult
    {
        /** @var list<ValidationError> $errors */
        $errors = [];

        $this->checkObjectShape('', $document, self::DOCUMENT_REQUIRED, [], $errors);
        $this->checkSchemaVersion($document, $errors);
        $this->checkDocumentId($document, $errors);
        $this->checkCoordinateSpace($document, $errors);

        $recipientIds = $this->checkRecipients($document, $errors);
        $this->checkSigningOrder($document, $recipientIds, $errors);
        $this->checkFields(
            $document,
            $recipientIds,
            $pageSizes,
            $variables,
            $this->declaredVersion($document),
            $errors,
        );

        return new ValidationResult($errors);
    }

    /**
     * Convenience for callers holding raw JSON. Malformed JSON is itself a validation error, so
     * a caller never has to catch two different failure shapes.
     */
    public function validateJson(string $json, ?PageSizes $pageSizes = null, ?array $variables = null): ValidationResult
    {
        $decoded = json_decode($json, true, 64);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new ValidationResult([
                new ValidationError('', ValidationCode::InvalidType, 'Document is not valid JSON: '.json_last_error_msg().'.'),
            ]);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return new ValidationResult([
                new ValidationError('', ValidationCode::InvalidType, 'Document must be a JSON object.'),
            ]);
        }

        return $this->validate($decoded, $pageSizes, $variables);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<ValidationError>  $errors
     */
    private function checkSchemaVersion(array $document, array &$errors): void
    {
        if (! array_key_exists('schema_version', $document)) {
            return;
        }

        $declared = $document['schema_version'];

        if (! is_string($declared)) {
            $errors[] = new ValidationError(
                '/schema_version',
                ValidationCode::InvalidType,
                'schema_version must be a string such as "'.SchemaVersion::CURRENT.'".',
            );

            return;
        }

        $version = SchemaVersion::parse($declared);

        if (! $version instanceof SchemaVersion) {
            $errors[] = new ValidationError(
                '/schema_version',
                ValidationCode::SchemaVersionUnsupported,
                'schema_version must be exactly MAJOR.MINOR, for example "'.SchemaVersion::CURRENT.'"; got "'.$declared.'".',
            );

            return;
        }

        if (! $version->isSupportedMajor()) {
            $errors[] = new ValidationError(
                '/schema_version',
                ValidationCode::SchemaVersionUnsupported,
                'Unknown major schema version '.$version->major.'. This build implements '
                    .SchemaVersion::MAJOR.'.x and refuses an unknown major version outright.',
            );

            return;
        }

        if (! $version->isSupported()) {
            $errors[] = new ValidationError(
                '/schema_version',
                ValidationCode::SchemaVersionUnsupported,
                'schema_version '.$version->toString().' is newer than the '.SchemaVersion::CURRENT
                    .' this build implements, so it may contain properties that would be dropped.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<ValidationError>  $errors
     */
    private function checkDocumentId(array $document, array &$errors): void
    {
        if (array_key_exists('document_id', $document)) {
            $this->checkIdentifier('/document_id', 'document_id', $document['document_id'], $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<ValidationError>  $errors
     */
    private function checkCoordinateSpace(array $document, array &$errors): void
    {
        if (! array_key_exists('coordinate_space', $document)) {
            return;
        }

        $space = $document['coordinate_space'];

        if (! $this->isObject($space)) {
            $errors[] = new ValidationError(
                '/coordinate_space',
                ValidationCode::InvalidType,
                'coordinate_space must be an object declaring unit, origin, page_box, rotation, and page_index_base.',
            );

            return;
        }

        $expected = CoordinateSpaceDeclaration::expected();
        $this->checkObjectShape('/coordinate_space', $space, array_keys($expected), [], $errors);

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $space)) {
                continue;
            }

            $declared = $space[$key];

            if ($key === 'page_index_base') {
                $declared = $this->asInteger($declared) ?? $declared;
            }

            if ($declared !== $value) {
                $errors[] = new ValidationError(
                    '/coordinate_space/'.$key,
                    ValidationCode::UnsupportedCoordinateSpace,
                    'Unsupported coordinate_space.'.$key.': schema '.SchemaVersion::CURRENT.' implements only '
                        .var_export($value, true).', got '.var_export($space[$key], true)
                        .'. A document declaring another convention is rejected, never reinterpreted.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<ValidationError>  $errors
     * @return list<string>|null Declared recipient ids, or null when the section is unusable and
     *                           reference checks against it would only produce noise.
     */
    private function checkRecipients(array $document, array &$errors): ?array
    {
        if (! array_key_exists('recipients', $document)) {
            return null;
        }

        $recipients = $document['recipients'];

        if (! is_array($recipients) || ! array_is_list($recipients)) {
            $errors[] = new ValidationError('/recipients', ValidationCode::InvalidType, 'recipients must be an array.');

            return null;
        }

        if ($recipients === []) {
            $errors[] = new ValidationError(
                '/recipients',
                ValidationCode::EmptyCollection,
                'recipients must declare at least one recipient.',
            );

            return [];
        }

        $ids = [];

        foreach ($recipients as $index => $recipient) {
            $path = '/recipients/'.$index;

            if (! $this->isObject($recipient)) {
                $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'A recipient must be an object.');

                continue;
            }

            $this->checkObjectShape($path, $recipient, self::RECIPIENT_REQUIRED, self::RECIPIENT_OPTIONAL, $errors);

            if (array_key_exists('id', $recipient)) {
                $id = $this->checkIdentifier($path.'/id', 'recipient id', $recipient['id'], $errors);

                if ($id !== null) {
                    if (in_array($id, $ids, true)) {
                        $errors[] = new ValidationError(
                            $path.'/id',
                            ValidationCode::DuplicateId,
                            'Duplicate recipient id "'.$id.'". Recipient ids are the handles fields and the signing order refer to, so they must be unique.',
                        );
                    } else {
                        $ids[] = $id;
                    }
                }
            }

            if (array_key_exists('name', $recipient)) {
                $this->checkNonEmptyString($path.'/name', 'recipient name', $recipient['name'], self::NAME_MAX_LENGTH, $errors);
            }

            if (array_key_exists('email', $recipient)) {
                $this->checkEmail($path.'/email', $recipient['email'], $errors);
            }

            if (array_key_exists('role', $recipient)) {
                $this->checkNonEmptyString($path.'/role', 'recipient role label', $recipient['role'], self::ROLE_MAX_LENGTH, $errors);
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>|null  $recipientIds
     * @param  list<ValidationError>  $errors
     */
    private function checkSigningOrder(array $document, ?array $recipientIds, array &$errors): void
    {
        if (! array_key_exists('signing_order', $document)) {
            return;
        }

        $order = $document['signing_order'];

        if (! is_array($order) || ! array_is_list($order)) {
            $errors[] = new ValidationError(
                '/signing_order',
                ValidationCode::InvalidType,
                'signing_order must be an array of stages, each stage an array of recipient ids.',
            );

            return;
        }

        if ($order === []) {
            $errors[] = new ValidationError(
                '/signing_order',
                ValidationCode::EmptyCollection,
                'signing_order must declare at least one stage.',
            );

            return;
        }

        /** @var list<string> $placed */
        $placed = [];
        $usable = true;

        foreach ($order as $stageIndex => $stage) {
            $stagePath = '/signing_order/'.$stageIndex;

            if (! is_array($stage) || ! array_is_list($stage)) {
                $errors[] = new ValidationError(
                    $stagePath,
                    ValidationCode::InvalidType,
                    'A signing stage must be an array of recipient ids; wrap a single recipient as ["id"].',
                );
                $usable = false;

                continue;
            }

            if ($stage === []) {
                $errors[] = new ValidationError(
                    $stagePath,
                    ValidationCode::EmptyCollection,
                    'A signing stage must contain at least one recipient.',
                );
                $usable = false;

                continue;
            }

            foreach ($stage as $position => $id) {
                $path = $stagePath.'/'.$position;

                if (! is_string($id)) {
                    $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'A signing stage entry must be a recipient id string.');
                    $usable = false;

                    continue;
                }

                if (in_array($id, $placed, true)) {
                    $errors[] = new ValidationError(
                        $path,
                        ValidationCode::RecipientDuplicatedInSigningOrder,
                        'Recipient "'.$id.'" appears more than once in signing_order. Each recipient signs in exactly one stage.',
                    );

                    continue;
                }

                $placed[] = $id;

                if ($recipientIds !== null && ! in_array($id, $recipientIds, true)) {
                    $errors[] = new ValidationError(
                        $path,
                        ValidationCode::UnknownRecipient,
                        'signing_order names recipient "'.$id.'", which is not declared in recipients.',
                    );
                }
            }
        }

        if (! $usable || $recipientIds === null) {
            return;
        }

        foreach ($recipientIds as $index => $id) {
            if (! in_array($id, $placed, true)) {
                $errors[] = new ValidationError(
                    '/recipients/'.$index.'/id',
                    ValidationCode::RecipientNotInSigningOrder,
                    'Recipient "'.$id.'" is declared but appears in no signing_order stage, so they would never be asked to sign.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>|null  $recipientIds
     * @param  list<string>|null  $variables
     * @param  list<ValidationError>  $errors
     */
    private function checkFields(
        array $document,
        ?array $recipientIds,
        ?PageSizes $pageSizes,
        ?array $variables,
        ?SchemaVersion $version,
        array &$errors,
    ): void {
        if (! array_key_exists('fields', $document)) {
            return;
        }

        $fields = $document['fields'];

        if (! is_array($fields) || ! array_is_list($fields)) {
            $errors[] = new ValidationError('/fields', ValidationCode::InvalidType, 'fields must be an array.');

            return;
        }

        $ids = [];
        $aliases = [];

        foreach ($fields as $index => $field) {
            $path = '/fields/'.$index;

            if (! $this->isObject($field)) {
                $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'A field must be an object.');

                continue;
            }

            $this->checkObjectShape($path, $field, self::FIELD_REQUIRED, self::FIELD_OPTIONAL, $errors);

            if (array_key_exists('id', $field)) {
                $id = $this->checkIdentifier($path.'/id', 'field id', $field['id'], $errors);

                if ($id !== null) {
                    if (in_array($id, $ids, true)) {
                        $errors[] = new ValidationError(
                            $path.'/id',
                            ValidationCode::DuplicateId,
                            'Duplicate field id "'.$id.'". Field ids are stable across import and export, so they must be unique.',
                        );
                    } else {
                        $ids[] = $id;
                    }
                }
            }

            if (array_key_exists('alias', $field)) {
                $alias = $this->checkIdentifier($path.'/alias', 'field alias', $field['alias'], $errors);

                if ($alias !== null) {
                    if (in_array($alias, $aliases, true)) {
                        $errors[] = new ValidationError(
                            $path.'/alias',
                            ValidationCode::DuplicateAlias,
                            'Duplicate field alias "'.$alias.'". Templates address fields by alias, so an alias resolves to exactly one field.',
                        );
                    } else {
                        $aliases[] = $alias;
                    }
                }
            }

            if (array_key_exists('recipient_id', $field)) {
                $recipientId = $this->checkIdentifier($path.'/recipient_id', 'recipient_id', $field['recipient_id'], $errors);

                if ($recipientId !== null && $recipientIds !== null && ! in_array($recipientId, $recipientIds, true)) {
                    $errors[] = new ValidationError(
                        $path.'/recipient_id',
                        ValidationCode::UnknownRecipient,
                        'Field is bound to recipient "'.$recipientId.'", which is not declared in recipients.',
                    );
                }
            }

            if (array_key_exists('type', $field)) {
                $this->checkFieldType($path.'/type', $field['type'], $errors);
            }

            if (array_key_exists('page', $field)) {
                $this->checkPage($path.'/page', $field['page'], $pageSizes, $errors);
            }

            if (array_key_exists('rect', $field)) {
                $this->checkRect($path.'/rect', $field['rect'], $field['page'] ?? null, $pageSizes, $errors);
            }

            foreach (['required', 'read_only'] as $flag) {
                if (array_key_exists($flag, $field) && ! is_bool($field[$flag])) {
                    $errors[] = new ValidationError(
                        $path.'/'.$flag,
                        ValidationCode::InvalidType,
                        $flag.' must be a boolean.',
                    );
                }
            }

            if (array_key_exists('label', $field)) {
                $this->checkNonEmptyString($path.'/label', 'label', $field['label'], self::LABEL_MAX_LENGTH, $errors);
            }

            if (array_key_exists('prefill', $field)) {
                $this->checkPrefill($path.'/prefill', $field['prefill'], $variables, $errors);
            }

            if (array_key_exists('anchor', $field)) {
                $this->checkAnchor(
                    $path.'/anchor',
                    $field['anchor'],
                    array_key_exists('required', $field) && is_bool($field['required'])
                        ? $field['required']
                        : FieldDefinition::DEFAULT_REQUIRED,
                    $field['rect'] ?? null,
                    $field['page'] ?? null,
                    $version,
                    $errors,
                );
            }
        }
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function checkFieldType(string $path, mixed $type, array &$errors): void
    {
        if (! is_string($type)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'type must be a string.');

            return;
        }

        if (FieldType::tryFrom($type) instanceof FieldType) {
            return;
        }

        $errors[] = new ValidationError(
            $path,
            ValidationCode::UnsupportedFieldType,
            'Unsupported field type "'.$type.'". Schema '.SchemaVersion::CURRENT.' implements '
                .implode(', ', FieldType::values()).'. An unsupported type is rejected, never ignored: '
                .'a silently dropped field is a field nobody was asked to complete.',
        );
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function checkPage(string $path, mixed $page, ?PageSizes $pageSizes, array &$errors): void
    {
        $number = $this->asInteger($page);

        if ($number === null) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'page must be an integer.');

            return;
        }

        if ($number < 1) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::PageOutOfRange,
                'page is 1-based (coordinate_space.page_index_base is 1); got '.$number.'.',
            );

            return;
        }

        if ($pageSizes instanceof PageSizes && $number > $pageSizes->pageCount()) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::PageOutOfRange,
                'page '.$number.' is beyond the '.$pageSizes->pageCount().'-page document.',
            );
        }
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function checkRect(string $path, mixed $rect, mixed $page, ?PageSizes $pageSizes, array &$errors): void
    {
        if (! $this->isObject($rect)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'rect must be an object with x, y, width, and height.');

            return;
        }

        $this->checkObjectShape($path, $rect, self::RECT_REQUIRED, [], $errors);

        $values = [];

        foreach (self::RECT_REQUIRED as $name) {
            if (! array_key_exists($name, $rect)) {
                continue;
            }

            $value = $rect[$name];

            if (! is_int($value) && ! is_float($value)) {
                $errors[] = new ValidationError($path.'/'.$name, ValidationCode::InvalidType, 'rect.'.$name.' must be a number.');

                continue;
            }

            $value = (float) $value;

            if (! is_finite($value)) {
                $errors[] = new ValidationError(
                    $path.'/'.$name,
                    ValidationCode::CoordinateNotFinite,
                    'rect.'.$name.' must be a finite number; got '.var_export($rect[$name], true).'.',
                );

                continue;
            }

            if (($name === 'x' || $name === 'y') && $value < 0.0) {
                $errors[] = new ValidationError(
                    $path.'/'.$name,
                    ValidationCode::CoordinateNegative,
                    'rect.'.$name.' must not be negative: the native origin is the top-left corner of the displayed page, so a negative value is off the page.',
                );

                continue;
            }

            if (($name === 'width' || $name === 'height') && $value <= 0.0) {
                $errors[] = new ValidationError(
                    $path.'/'.$name,
                    ValidationCode::DimensionNotPositive,
                    'rect.'.$name.' must be greater than zero; got '.$this->describeNumber($value).'.',
                );

                continue;
            }

            $values[$name] = CanonicalNumber::round($value);
        }

        if (count($values) !== count(self::RECT_REQUIRED)) {
            return;
        }

        $pageNumber = $this->asInteger($page);

        if ($pageNumber === null || ! $pageSizes instanceof PageSizes || ! $pageSizes->has($pageNumber)) {
            return;
        }

        $size = $pageSizes->of($pageNumber);
        $right = CanonicalNumber::round($values['x'] + $values['width']);
        $bottom = CanonicalNumber::round($values['y'] + $values['height']);

        if ($right > $size['width'] + CanonicalNumber::TOLERANCE || $bottom > $size['height'] + CanonicalNumber::TOLERANCE) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::RectOutOfPage,
                'rect extends to ('.$this->describeNumber($right).', '.$this->describeNumber($bottom).') on page '.$pageNumber
                    .', which is '.$this->describeNumber($size['width']).' by '.$this->describeNumber($size['height']).' pt.',
            );
        }
    }

    /**
     * @param  list<string>|null  $variables
     * @param  list<ValidationError>  $errors
     */
    private function checkPrefill(string $path, mixed $prefill, ?array $variables, array &$errors): void
    {
        if (! $this->isObject($prefill)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'prefill must be an object with a variable.');

            return;
        }

        $this->checkObjectShape($path, $prefill, ['variable'], [], $errors);

        if (! array_key_exists('variable', $prefill)) {
            return;
        }

        $variable = $prefill['variable'];

        if (! is_string($variable)) {
            $errors[] = new ValidationError($path.'/variable', ValidationCode::InvalidType, 'prefill.variable must be a string.');

            return;
        }

        if ($variable === '' || strlen($variable) > self::VARIABLE_MAX_LENGTH || preg_match(self::VARIABLE_PATTERN, $variable) !== 1) {
            $errors[] = new ValidationError(
                $path.'/variable',
                ValidationCode::InvalidFormat,
                'prefill.variable must be a dotted lower-snake name such as "recipient.name"; got "'.$variable.'".',
            );

            return;
        }

        if ($variables !== null && ! in_array($variable, $variables, true)) {
            $errors[] = new ValidationError(
                $path.'/variable',
                ValidationCode::UnresolvedPrefillVariable,
                'prefill.variable "'.$variable.'" cannot be resolved by the sending context. An unresolved variable is an error before send, never a blank field.',
            );
        }
    }

    /**
     * @param  bool  $fieldRequired  The field's own `required` flag, which bounds `anchor.required`.
     * @param  mixed  $fieldRect  The field's own rectangle, which a `replace` receipt must reproduce.
     * @param  list<ValidationError>  $errors
     */
    private function checkAnchor(
        string $path,
        mixed $anchor,
        bool $fieldRequired,
        mixed $fieldRect,
        mixed $fieldPage,
        ?SchemaVersion $version,
        array &$errors,
    ): void {
        if (! $this->isObject($anchor)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'anchor must be an object with the text to locate.');

            return;
        }

        $this->checkObjectShape($path, $anchor, self::ANCHOR_REQUIRED, self::ANCHOR_OPTIONAL, $errors);
        $this->checkAnchorMembersAreDeclared($path, $anchor, $version, $errors);

        if (array_key_exists('text', $anchor)) {
            $this->checkNonEmptyString($path.'/text', 'anchor.text', $anchor['text'], self::ANCHOR_TEXT_MAX_LENGTH, $errors);
        }

        if (array_key_exists('occurrence', $anchor)) {
            $this->checkAnchorOccurrence($path.'/occurrence', $anchor['occurrence'], $errors);
        }

        $mode = $this->checkAnchorPlacement($path.'/placement', $anchor, $errors)
            ?? AnchorPlacement::DEFAULT_PLACEMENT;
        $this->checkAnchorRequired($path, $anchor, $fieldRequired, $mode, $errors);
        $this->checkAnchorTolerance($path.'/tolerance', $anchor, $mode, $errors);

        if (array_key_exists('resolved', $anchor)) {
            $declaredTolerance = $anchor['tolerance'] ?? null;

            $this->checkResolvedAnchor(
                $path.'/resolved',
                $anchor['resolved'],
                $mode,
                is_int($declaredTolerance) || is_float($declaredTolerance) ? (float) $declaredTolerance : null,
                $fieldRect,
                $fieldPage,
                $anchor['occurrence'] ?? null,
                $errors,
            );
        }

        if (array_key_exists('origin', $anchor)) {
            $origin = $anchor['origin'];

            if (! is_string($origin)) {
                $errors[] = new ValidationError($path.'/origin', ValidationCode::InvalidType, 'anchor.origin must be a string.');
            } elseif (! AnchorOrigin::tryFrom($origin) instanceof AnchorOrigin) {
                $errors[] = new ValidationError(
                    $path.'/origin',
                    ValidationCode::InvalidFormat,
                    'anchor.origin must be one of '.implode(', ', array_map(
                        static fn (AnchorOrigin $corner): string => $corner->value,
                        AnchorOrigin::cases(),
                    )).'; got "'.$origin.'". Nothing is inferred from the sign of the offset.',
                );
            }
        }

        if (! array_key_exists('offset', $anchor)) {
            return;
        }

        $offset = $anchor['offset'];

        if (! $this->isObject($offset)) {
            $errors[] = new ValidationError($path.'/offset', ValidationCode::InvalidType, 'anchor.offset must be an object with dx and dy.');

            return;
        }

        $this->checkObjectShape($path.'/offset', $offset, ['dx', 'dy'], [], $errors);

        foreach (['dx', 'dy'] as $name) {
            if (! array_key_exists($name, $offset)) {
                continue;
            }

            $value = $offset[$name];

            if (! is_int($value) && ! is_float($value)) {
                $errors[] = new ValidationError($path.'/offset/'.$name, ValidationCode::InvalidType, 'anchor.offset.'.$name.' must be a number.');

                continue;
            }

            if (! is_finite((float) $value)) {
                $errors[] = new ValidationError(
                    $path.'/offset/'.$name,
                    ValidationCode::CoordinateNotFinite,
                    'anchor.offset.'.$name.' must be a finite number; got '.var_export($value, true).'.',
                );
            }
        }
    }

    /**
     * The version a document declares, or null when it does not declare a usable one.
     *
     * Only used to decide which members a document is allowed to contain; every other check is
     * version-independent, and a document with no readable version has already been reported.
     *
     * @param  array<string, mixed>  $document
     */
    private function declaredVersion(array $document): ?SchemaVersion
    {
        $declared = $document['schema_version'] ?? null;

        return is_string($declared) ? SchemaVersion::parse($declared) : null;
    }

    /**
     * An anchor may only use members the version it declares actually declares.
     *
     * Without this the version string is a label rather than a contract: a generator could stamp
     * `1.0` and emit a `resolved` receipt, and every consumer holding `field-schema-1.0.json` —
     * which forbids undeclared properties — would reject a document this service called valid.
     * The error is `unknown_property` because that is exactly what such a consumer would say.
     *
     * @param  array<string, mixed>  $anchor
     * @param  list<ValidationError>  $errors
     */
    private function checkAnchorMembersAreDeclared(
        string $path,
        array $anchor,
        ?SchemaVersion $version,
        array &$errors,
    ): void {
        if (! $version instanceof SchemaVersion || $version->minor >= self::ANCHOR_MEMBERS_MINOR) {
            return;
        }

        foreach (self::ANCHOR_MEMBERS_SINCE_1_1 as $member) {
            if (! array_key_exists($member, $anchor)) {
                continue;
            }

            $errors[] = new ValidationError(
                $path.'/'.$member,
                ValidationCode::UnknownProperty,
                'anchor.'.$member.' arrived in schema '.self::MAJOR_MINOR_1_1.', and this document declares '
                    .$version->toString().'. Declare '.self::MAJOR_MINOR_1_1.' to use it: a document that says '
                    .$version->toString().' is read against a contract that does not have it, and refusing an '
                    .'undeclared property is what that contract does.',
            );
        }
    }

    /**
     * Which of the field's two statements about position wins.
     *
     * Omitted means `replace`, and that is not the kind of default `anchor.occurrence` refuses.
     * There, two readings are equally plausible and picking one silently moves a box. Here there
     * is one reading with any history behind it: before `cross_check` existed, an anchor wrote
     * its resolved rectangle into the field and that was all an anchor could do. So the default
     * is what an already-written document meant, which is also why it must stay the default —
     * see {@see AnchorPlacement::DEFAULT_PLACEMENT} for the digest that depends on it.
     *
     * @param  array<string, mixed>  $anchor
     * @param  list<ValidationError>  $errors
     */
    private function checkAnchorPlacement(string $path, array $anchor, array &$errors): ?AnchorPlacementMode
    {
        if (! array_key_exists('placement', $anchor)) {
            return null;
        }

        $placement = $anchor['placement'];

        if (! is_string($placement)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'anchor.placement must be a string.');

            return null;
        }

        $mode = AnchorPlacementMode::tryFrom($placement);

        if (! $mode instanceof AnchorPlacementMode) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidFormat,
                'anchor.placement must be one of '.implode(', ', AnchorPlacementMode::values()).'; got "'.$placement
                    .'". "'.AnchorPlacementMode::Replace->value.'" lets the anchor decide where the field goes and '
                    .'keeps only the rectangle\'s size; "'.AnchorPlacementMode::CrossCheck->value.'" keeps the '
                    .'declared rectangle and requires the anchor to agree with it. Omitting the property is '
                    .'"'.AnchorPlacementMode::Replace->value.'", which is what an anchor has always meant here; '
                    .'"'.AnchorPlacementMode::CrossCheck->value.'" is the narrower mode and has to be stated.',
            );

            return null;
        }

        return $mode;
    }

    /**
     * The narrow compatibility option for an anchor that is allowed not to be there.
     *
     * `anchor.required: false` says "this text may legitimately be absent from this document, and
     * if it is, do not place the field at all". That can only be true of a field nobody has to
     * fill in, so it is refused on a required field rather than quietly making a required field
     * unfillable. Ambiguity is never acceptable either way: an absent anchor is a decision the
     * document can express, and two matches where one was asked for is always an error.
     *
     * @param  array<string, mixed>  $anchor
     * @param  list<ValidationError>  $errors
     */
    private function checkAnchorRequired(
        string $path,
        array $anchor,
        bool $fieldRequired,
        AnchorPlacementMode $mode,
        array &$errors,
    ): void {
        if (! array_key_exists('required', $anchor)) {
            return;
        }

        $required = $anchor['required'];

        if (! is_bool($required)) {
            $errors[] = new ValidationError($path.'/required', ValidationCode::InvalidType, 'anchor.required must be a boolean.');

            return;
        }

        if ($required) {
            return;
        }

        if ($fieldRequired) {
            $errors[] = new ValidationError(
                $path.'/required',
                ValidationCode::AnchorOptionalOnRequiredField,
                'anchor.required is false on a field whose own "required" is true. An absent anchor omits the field, '
                    .'and a required field that is never placed can never be completed. Make the field optional, or '
                    .'require the anchor.',
            );

            return;
        }

        // In cross-check mode the rectangle is authoritative and the anchor only confirms it, so
        // "the text may be absent" has nothing to say: there is no placement waiting on the
        // anchor to omit. Honouring it would delete a field the document positioned itself.
        if ($mode === AnchorPlacementMode::CrossCheck) {
            $errors[] = new ValidationError(
                $path.'/required',
                ValidationCode::InvalidFormat,
                'anchor.required false means an absent anchor omits the field, which contradicts anchor.placement "'
                    .AnchorPlacementMode::CrossCheck->value.'": there the declared rectangle is authoritative and the '
                    .'anchor only checks it, so an absent anchor has nothing to omit. Use "'
                    .AnchorPlacementMode::Replace->value.'", or require the anchor.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $anchor
     * @param  list<ValidationError>  $errors
     */
    private function checkAnchorTolerance(string $path, array $anchor, AnchorPlacementMode $mode, array &$errors): void
    {
        if (! array_key_exists('tolerance', $anchor)) {
            return;
        }

        $tolerance = $anchor['tolerance'];

        if (! is_int($tolerance) && ! is_float($tolerance)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'anchor.tolerance must be a number of points.');

            return;
        }

        if (! is_finite((float) $tolerance)) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::CoordinateNotFinite,
                'anchor.tolerance must be a finite number; got '.var_export($tolerance, true).'.',
            );

            return;
        }

        if ((float) $tolerance < 0.0) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidFormat,
                'anchor.tolerance is a distance in points and must not be negative; got '.$this->describeNumber((float) $tolerance).'.',
            );

            return;
        }

        if ($mode === AnchorPlacementMode::Replace) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidFormat,
                'anchor.tolerance only means something with anchor.placement "'.AnchorPlacementMode::CrossCheck->value
                    .'". In "'.AnchorPlacementMode::Replace->value.'" mode the anchor decides the position outright, '
                    .'so there is no declared rectangle to be within a tolerance of.',
            );
        }
    }

    /**
     * The resolution receipt, which resolution writes and a round trip must be able to read back.
     *
     * It is validated as strictly as anything a caller sends. An envelope re-reads its own stored
     * schema through this validator on every request, so a receipt this importer would refuse is a
     * receipt that must never be written.
     *
     * @param  list<ValidationError>  $errors
     */
    private function checkResolvedAnchor(
        string $path,
        mixed $resolved,
        AnchorPlacementMode $mode,
        ?float $anchorTolerance,
        mixed $fieldRect,
        mixed $fieldPage,
        mixed $requestedOccurrence,
        array &$errors,
    ): void {
        if (! $this->isObject($resolved)) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidType,
                'anchor.resolved must be an object recording what resolution found.',
            );

            return;
        }

        $this->checkObjectShape($path, $resolved, self::RESOLVED_ANCHOR_REQUIRED, [], $errors);

        if (array_key_exists('document_sha256', $resolved)) {
            $digest = $resolved['document_sha256'];

            if (! is_string($digest) || preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
                $errors[] = new ValidationError(
                    $path.'/document_sha256',
                    ValidationCode::InvalidFormat,
                    'anchor.resolved.document_sha256 must be 64 lowercase hexadecimal characters: the digest of the '
                        .'exact bytes the text was located in.',
                );
            }
        }

        $recorded = [];

        foreach (['page', 'occurrence_index'] as $name) {
            if (! array_key_exists($name, $resolved)) {
                continue;
            }

            $value = $this->asInteger($resolved[$name]);

            if ($value === null) {
                $errors[] = new ValidationError(
                    $path.'/'.$name,
                    ValidationCode::InvalidType,
                    'anchor.resolved.'.$name.' must be an integer.',
                );

                continue;
            }

            if ($value < 1) {
                $errors[] = new ValidationError(
                    $path.'/'.$name,
                    $name === 'page' ? ValidationCode::PageOutOfRange : ValidationCode::InvalidFormat,
                    'anchor.resolved.'.$name.' is 1-based; got '.$value.'.',
                );

                continue;
            }

            $recorded[$name] = $value;
        }

        $this->checkReceiptAnswersTheRequest($path, $recorded, $fieldPage, $requestedOccurrence, $errors);

        // `anchor_rect` records where the text was, not where anything goes. A run's nominal box
        // is its advance by the font's ascent plus descent, so a heading near the top of the page
        // legitimately starts above the CropBox edge, and a run touching the right margin
        // legitimately ends on it. Checking it as a placement — non-negative, inside the page —
        // would refuse ordinary documents, and would refuse receipts this service itself writes.
        if (array_key_exists('anchor_rect', $resolved)) {
            $this->checkMeasuredRect($path.'/anchor_rect', $resolved['anchor_rect'], $errors);
        }

        if (! array_key_exists('rect', $resolved)) {
            return;
        }

        // The resolved rectangle *is* a placement, so it is checked like one. The page-fit check
        // is deliberately not run here: it belongs to the field's own rect, which in `replace`
        // mode is required to be this same rectangle.
        $this->checkRect($path.'/rect', $resolved['rect'], null, null, $errors);

        if (! $this->isObject($fieldRect) || ! $this->isObject($resolved['rect'])) {
            return;
        }

        if ($mode === AnchorPlacementMode::Replace) {
            $this->checkReplaceReceiptMatchesRect($path, $resolved['rect'], $fieldRect, $errors);

            return;
        }

        $this->checkCrossCheckReceiptAgrees($path, $anchorTolerance, $resolved['rect'], $fieldRect, $errors);
    }

    /**
     * A receipt answers one question, and it has to be the question the field is asking now.
     *
     * The receipt is what lets resolution be skipped, so nothing re-reads the document once one
     * is present for its digest. Change `field.page` or `anchor.occurrence` afterwards and the
     * old answer would be kept: the field would publish and send at coordinates resolved for a
     * different page, or for a different occurrence of the same text, with a receipt that looks
     * entirely well-formed. Requiring the receipt to restate the request is what makes editing
     * the request invalidate it.
     *
     * @param  array<string, int>  $recorded
     * @param  list<ValidationError>  $errors
     */
    private function checkReceiptAnswersTheRequest(
        string $path,
        array $recorded,
        mixed $fieldPage,
        mixed $requestedOccurrence,
        array &$errors,
    ): void {
        $page = $this->asInteger($fieldPage);

        if ($page !== null && isset($recorded['page']) && $recorded['page'] !== $page) {
            $errors[] = new ValidationError(
                $path.'/page',
                ValidationCode::PageOutOfRange,
                'anchor.resolved.page is '.$recorded['page'].' and the field is on page '.$page.'. An anchor is '
                    .'searched on the page its field declares, so a receipt for another page answers a question '
                    .'this field is no longer asking; move the field back or drop the receipt so it resolves again.',
            );
        }

        if (! isset($recorded['occurrence_index'])) {
            return;
        }

        // "sole" means the text occurs once, so the match taken is always the first.
        $expected = $requestedOccurrence === AnchorPlacement::OCCURRENCE_SOLE
            ? 1
            : $this->asInteger($requestedOccurrence);

        if ($expected === null || $recorded['occurrence_index'] === $expected) {
            return;
        }

        $errors[] = new ValidationError(
            $path.'/occurrence_index',
            ValidationCode::InvalidFormat,
            'anchor.resolved.occurrence_index is '.$recorded['occurrence_index'].' and the anchor asks for '
                .($requestedOccurrence === AnchorPlacement::OCCURRENCE_SOLE
                    ? 'the sole occurrence'
                    : 'occurrence '.$expected)
                .'. A receipt records which match was taken, so one for a different match is not an answer to '
                .'this anchor; drop it so the anchor resolves again.',
        );
    }

    /**
     * In `replace` mode the receipt's rectangle is where the field went, so the two must agree
     * exactly. A document whose field sits somewhere its own receipt does not describe is a
     * document nobody can check.
     *
     * @param  array<string, mixed>  $recorded
     * @param  array<string, mixed>  $declared
     * @param  list<ValidationError>  $errors
     */
    private function checkReplaceReceiptMatchesRect(
        string $path,
        array $recorded,
        array $declared,
        array &$errors,
    ): void {
        // Canonical values, because canonical values are what will be stored: comparing what the
        // caller wrote would accept a document that stops importing the moment it is written down.
        foreach (self::RECT_REQUIRED as $name) {
            $pair = $this->canonicalPair($declared[$name] ?? null, $recorded[$name] ?? null);

            if ($pair === null) {
                return;
            }

            if (abs($pair[0] - $pair[1]) > CanonicalNumber::TOLERANCE) {
                $errors[] = new ValidationError(
                    $path.'/rect/'.$name,
                    ValidationCode::InvalidFormat,
                    'anchor.resolved.rect must be the field\'s own rect when anchor.placement is "'
                        .AnchorPlacementMode::Replace->value.'": the receipt records where the field was placed, and '
                        .'this one says '.$this->describeNumber($pair[1]).' where the field says '
                        .$this->describeNumber($pair[0]).'.',
                );

                return;
            }
        }
    }

    /**
     * A `cross_check` receipt has to prove the check it claims to be.
     *
     * This is the whole feature. A stored receipt is read back on every request — the envelope
     * re-imports its own schema — and it is the only account of the check anyone reading the
     * document afterwards has. If nothing here compared the resolved corner against the declared
     * one, a receipt could record any disagreement at all and still be read back as valid. That
     * is worse than not having the mode: the document would carry a record saying it had been
     * checked when nothing ever checked it.
     *
     * The comparison uses the anchor's *own* `tolerance`, which is why one is required alongside
     * a cross-check receipt: the deployment default can change, and a receipt whose standard has
     * to be looked up elsewhere proves nothing about what was actually applied.
     *
     * @param  array<string, mixed>  $recorded
     * @param  array<string, mixed>  $declared
     * @param  list<ValidationError>  $errors
     */
    private function checkCrossCheckReceiptAgrees(
        string $path,
        ?float $tolerance,
        array $recorded,
        array $declared,
        array &$errors,
    ): void {
        if ($tolerance === null) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::MissingProperty,
                'A "'.AnchorPlacementMode::CrossCheck->value.'" anchor carrying anchor.resolved must also state '
                    .'anchor.tolerance: the receipt is the record that the check passed, and without the distance '
                    .'it passed by there is nothing to check it against.',
            );

            return;
        }

        // Rounded first, and the tolerance with them. Import canonicalises every coordinate to
        // three decimals independently, so comparing the raw values would let a document pass on
        // numbers it will not have once it is stored: a declared 330.0004 and a resolved
        // 331.00179 are 1.00139 apart and inside a stated 1.0004, and they canonicalise to 330,
        // 331.002 and 1 — 1.002 apart and outside. The document would be accepted once and
        // refused by its own next import, which is the one thing a coordinate-stable round trip
        // must not do.
        $slack = CanonicalNumber::round($tolerance);

        // The resolved rectangle is the matched text's position at *the field's own size* — an
        // anchor says where a field goes and never how big it is. A receipt whose extents are not
        // the field's therefore records a result no resolution could have produced, so they are
        // compared exactly while the corner is compared within the tolerance.
        foreach (['width', 'height'] as $name) {
            $pair = $this->canonicalPair($declared[$name] ?? null, $recorded[$name] ?? null);

            if ($pair === null) {
                return;
            }

            if (abs($pair[0] - $pair[1]) > CanonicalNumber::TOLERANCE) {
                $errors[] = new ValidationError(
                    $path.'/rect/'.$name,
                    ValidationCode::AnchorCrossCheckFailed,
                    'anchor.resolved records a '.$name.' of '.$this->describeNumber($pair[1]).' and the field is '
                        .$this->describeNumber($pair[0]).' wide by its own declaration. An anchor decides where a '
                        .'field goes and never how big it is, so a receipt of another size is not a record of '
                        .'resolving this field.',
                );

                return;
            }
        }

        foreach (['x', 'y'] as $name) {
            $pair = $this->canonicalPair($declared[$name] ?? null, $recorded[$name] ?? null);

            if ($pair === null) {
                return;
            }

            // The stated bound, enforced exactly. Both sides and the tolerance are already
            // canonical, so the page-edge epsilon has nothing left to absorb here and would only
            // widen what the document says: a receipt 0.001 pt outside a stated `tolerance: 0`
            // would pass and then be stored, unchanged, saying it had been checked to zero. The
            // distance is rounded rather than compared raw because subtracting two three-decimal
            // values can land a few ulps above the bound they are exactly on.
            $distance = CanonicalNumber::round(abs($pair[0] - $pair[1]));

            if ($distance > $slack) {
                $errors[] = new ValidationError(
                    $path.'/rect/'.$name,
                    ValidationCode::AnchorCrossCheckFailed,
                    'anchor.resolved records a '.$name.' of '.$this->describeNumber($pair[1]).' against a declared '
                        .$name.' of '.$this->describeNumber($pair[0]).', which is '.$this->describeNumber($distance)
                        .' pt apart and outside the '.$this->describeNumber($slack).' pt tolerance the anchor '
                        .'states. A receipt that records a failed check is not a record that the check passed.',
                );

                return;
            }
        }
    }

    /**
     * The same pair as {@see numericPair()}, rounded the way import will round it.
     *
     * Every comparison a stored document has to survive is made on canonical values, because
     * canonical values are what will be stored. Comparing what the caller wrote instead accepts
     * documents that stop importing the moment they are written down.
     *
     * @return array{float, float}|null
     */
    private function canonicalPair(mixed $declared, mixed $recorded): ?array
    {
        $pair = $this->numericPair($declared, $recorded);

        if ($pair === null || ! is_finite($pair[0]) || ! is_finite($pair[1])) {
            return null;
        }

        return [CanonicalNumber::round($pair[0]), CanonicalNumber::round($pair[1])];
    }

    /**
     * Two numbers to compare, or null when either is not a number — in which case the type error
     * has already been reported and there is nothing useful to say about the difference.
     *
     * @return array{float, float}|null
     */
    private function numericPair(mixed $declared, mixed $recorded): ?array
    {
        if ((! is_int($declared) && ! is_float($declared)) || (! is_int($recorded) && ! is_float($recorded))) {
            return null;
        }

        return [(float) $declared, (float) $recorded];
    }

    /**
     * A rectangle that records where something was, rather than where something goes.
     *
     * Finite, with non-negative extents, and nothing else: see {@see MeasuredRect} for why a
     * negative coordinate and an overhanging edge are both ordinary here.
     *
     * @param  list<ValidationError>  $errors
     */
    private function checkMeasuredRect(string $path, mixed $rect, array &$errors): void
    {
        if (! $this->isObject($rect)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'rect must be an object with x, y, width, and height.');

            return;
        }

        $this->checkObjectShape($path, $rect, self::RECT_REQUIRED, [], $errors);

        foreach (self::RECT_REQUIRED as $name) {
            if (! array_key_exists($name, $rect)) {
                continue;
            }

            $value = $rect[$name];

            if (! is_int($value) && ! is_float($value)) {
                $errors[] = new ValidationError($path.'/'.$name, ValidationCode::InvalidType, 'rect.'.$name.' must be a number.');

                continue;
            }

            if (! is_finite((float) $value)) {
                $errors[] = new ValidationError(
                    $path.'/'.$name,
                    ValidationCode::CoordinateNotFinite,
                    'rect.'.$name.' must be a finite number; got '.var_export($value, true).'.',
                );

                continue;
            }

            if (($name === 'width' || $name === 'height') && (float) $value < 0.0) {
                $errors[] = new ValidationError(
                    $path.'/'.$name,
                    ValidationCode::DimensionNotPositive,
                    'rect.'.$name.' must not be negative; got '.$this->describeNumber((float) $value).'.',
                );
            }
        }
    }

    /**
     * `"sole"` or a 1-based index, and nothing else.
     *
     * `Text\AnchorOccurrence` refuses a "first match wins" default, so a document that does not
     * say which match it means is under-specified rather than defaulted. `"all"` is a resolver
     * capability that places one box per match, which a single field with a single id cannot
     * represent, so it is rejected here with that explanation instead of being half-honoured.
     *
     * @param  list<ValidationError>  $errors
     */
    private function checkAnchorOccurrence(string $path, mixed $occurrence, array &$errors): void
    {
        if (is_string($occurrence)) {
            if ($occurrence === AnchorPlacement::OCCURRENCE_SOLE) {
                return;
            }

            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidFormat,
                'anchor.occurrence must be "'.AnchorPlacement::OCCURRENCE_SOLE.'" or a 1-based index; got "'.$occurrence
                    .'". "all" places one box per match, which a single field cannot represent: use one field per box.',
            );

            return;
        }

        $index = $this->asInteger($occurrence);

        if ($index === null) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidType,
                'anchor.occurrence must be "'.AnchorPlacement::OCCURRENCE_SOLE.'" or an integer index.',
            );

            return;
        }

        if ($index < 1) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidFormat,
                'anchor.occurrence indexes are 1-based; got '.$index.'.',
            );
        }
    }

    /**
     * Missing required properties and undeclared extra properties, in one place.
     *
     * Undeclared properties are refused rather than dropped: silently discarding a property is
     * how a document that says "read only" becomes one that says nothing.
     *
     * @param  array<string, mixed>  $value
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @param  list<ValidationError>  $errors
     */
    private function checkObjectShape(string $path, array $value, array $required, array $optional, array &$errors): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[] = new ValidationError(
                    $path,
                    ValidationCode::MissingProperty,
                    'Missing required property "'.$key.'". A partial document is rejected, not completed with defaults.',
                );
            }
        }

        $known = array_merge($required, $optional);

        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $errors[] = new ValidationError(
                    $path === '' ? '/'.$key : $path.'/'.$key,
                    ValidationCode::UnknownProperty,
                    'Unknown property "'.$key.'". Schema '.SchemaVersion::CURRENT.' declares '.implode(', ', $known)
                        .'; an undeclared property is refused rather than silently ignored.',
                );
            }
        }
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function checkIdentifier(string $path, string $label, mixed $value, array &$errors): ?string
    {
        if (! is_string($value)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, $label.' must be a string.');

            return null;
        }

        if ($value === '' || strlen($value) > self::IDENTIFIER_MAX_LENGTH || preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidFormat,
                $label.' must be 1 to '.self::IDENTIFIER_MAX_LENGTH.' characters of letters, digits, dot, underscore, or hyphen, starting with a letter or digit; got "'.$value.'".',
            );

            return null;
        }

        return $value;
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function checkNonEmptyString(string $path, string $label, mixed $value, int $maxLength, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, $label.' must be a string.');

            return;
        }

        if ($value === '') {
            $errors[] = new ValidationError($path, ValidationCode::InvalidFormat, $label.' must not be empty. Omit the property instead.');

            return;
        }

        if (mb_strlen($value) > $maxLength) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidFormat,
                $label.' must be at most '.$maxLength.' characters; got '.mb_strlen($value).'.',
            );
        }
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function checkEmail(string $path, mixed $value, array &$errors): void
    {
        if (! is_string($value)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'recipient email must be a string.');

            return;
        }

        if ($value === '' || strlen($value) > self::EMAIL_MAX_LENGTH || preg_match(self::EMAIL_PATTERN, $value) !== 1) {
            $errors[] = new ValidationError(
                $path,
                ValidationCode::InvalidEmail,
                'recipient email must be a deliverable-looking address; got "'.$value.'".',
            );
        }
    }

    /** A JSON object decodes to a PHP map; a JSON array decodes to a list. */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && (! array_is_list($value) || $value === []);
    }

    /**
     * JSON has one number type, so `1` and `1.0` are the same integer. Accept either and
     * normalise; the canonical form writes the integer.
     */
    private function asInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && $value === floor($value) && abs($value) <= (float) PHP_INT_MAX) {
            return (int) $value;
        }

        return null;
    }

    private function describeNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, CanonicalNumber::DECIMALS, '.', ''), '0'), '.');
    }
}
