<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

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
        $this->checkFields($document, $recipientIds, $pageSizes, $variables, $errors);

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
                $this->checkAnchor($path.'/anchor', $field['anchor'], $errors);
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
     * @param  list<ValidationError>  $errors
     */
    private function checkAnchor(string $path, mixed $anchor, array &$errors): void
    {
        if (! $this->isObject($anchor)) {
            $errors[] = new ValidationError($path, ValidationCode::InvalidType, 'anchor must be an object with the text to locate.');

            return;
        }

        $this->checkObjectShape($path, $anchor, ['text'], ['occurrence', 'offset'], $errors);

        if (array_key_exists('text', $anchor)) {
            $this->checkNonEmptyString($path.'/text', 'anchor.text', $anchor['text'], self::ANCHOR_TEXT_MAX_LENGTH, $errors);
        }

        if (array_key_exists('occurrence', $anchor)) {
            $occurrence = $this->asInteger($anchor['occurrence']);

            if ($occurrence === null) {
                $errors[] = new ValidationError($path.'/occurrence', ValidationCode::InvalidType, 'anchor.occurrence must be an integer.');
            } elseif ($occurrence < 1) {
                $errors[] = new ValidationError(
                    $path.'/occurrence',
                    ValidationCode::InvalidFormat,
                    'anchor.occurrence is 1-based; got '.$occurrence.'.',
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
