<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

/**
 * One reason a field document was rejected, addressed to a place inside it.
 *
 * `path` is an RFC 6901 JSON Pointer into the document as submitted (`/fields/3/rect/width`),
 * with the empty string meaning the document root. The editor uses it to put the message on the
 * offending field instead of showing a generic "invalid document".
 */
final readonly class ValidationError
{
    public function __construct(
        public string $path,
        public ValidationCode $code,
        public string $message,
    ) {}

    /**
     * @return array{path: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'code' => $this->code->value,
            'message' => $this->message,
        ];
    }

    public function describe(): string
    {
        return ($this->path === '' ? '(document)' : $this->path).' ['.$this->code->value.'] '.$this->message;
    }
}
