<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

use Com\Tecnick\Pdf\Parser\Exception;
use Com\Tecnick\Pdf\Parser\Parser;

/**
 * A thin, typed reader over the raw object array produced by tc-lib-pdf-parser.
 *
 * The parser hands back objects as nested `[type, value, offset]` triples, where a
 * dictionary is a flat alternating list of key/value entries. Every consumer in this
 * module goes through this class so that shape is decoded in exactly one place.
 *
 * @phpstan-type RawEntry array{0: string, 1: mixed, 2?: int}
 */
final class PdfObjectGraph
{
    /** @var array<string, array<int, array<int, mixed>>> */
    private array $objects;

    /** @var array<string, mixed> */
    private array $trailer;

    /** @var array<string, bool> Guards against reference cycles while resolving. */
    private array $resolving = [];

    /**
     * @param  array<string, mixed>  $trailer
     * @param  array<string, array<int, array<int, mixed>>>  $objects
     */
    private function __construct(array $trailer, array $objects)
    {
        $this->trailer = $trailer;
        $this->objects = $objects;
    }

    /**
     * @throws Exception
     */
    public static function parse(string $pdfBytes, int $maxStreamBytes = 33_554_432): self
    {
        $parser = new Parser([
            'decode_streams' => true,
            'ignore_filter_errors' => false,
            'max_stream_size' => $maxStreamBytes,
        ]);
        [$xref, $objects] = $parser->parse($pdfBytes);

        /** @var array<string, mixed> $trailer */
        $trailer = $xref['trailer'];

        /** @var array<string, array<int, array<int, mixed>>> $objects */
        return new self($trailer, $objects);
    }

    /** @return array<string, mixed> */
    public function trailer(): array
    {
        return $this->trailer;
    }

    public function objectCount(): int
    {
        return count($this->objects);
    }

    /** @return array<int, string> */
    public function objectRefs(): array
    {
        return array_keys($this->objects);
    }

    public function isEncrypted(): bool
    {
        $encrypt = $this->trailer['encrypt'] ?? null;

        return is_string($encrypt) && $encrypt !== '';
    }

    public function rootRef(): ?string
    {
        $root = $this->trailer['root'] ?? null;

        return is_string($root) && $root !== '' ? $root : null;
    }

    /**
     * The top-level entries of an indirect object, e.g. `[['<<', [...]], ['stream', '...']]`.
     *
     * @return array<int, array<int, mixed>>
     */
    public function object(string $ref): array
    {
        return $this->objects[$ref] ?? [];
    }

    /**
     * The dictionary of an indirect object, as an associative array keyed by name.
     *
     * @return array<string, array<int, mixed>>|null
     */
    public function dictionary(string $ref): ?array
    {
        foreach ($this->object($ref) as $entry) {
            if (($entry[0] ?? null) === '<<') {
                return $this->decodeDictionary($entry);
            }
        }

        return null;
    }

    /**
     * The decoded stream payload of an indirect object, if it has one.
     *
     * tc-lib-pdf-parser puts the decoded bytes in element 3 as `[decoded, leftoverFilters]`
     * and leaves element 1 as the raw payload. A non-empty leftover filter list means the
     * chain could not be fully applied (an image codec, typically), in which case there is
     * no usable text payload and null is returned rather than compressed noise.
     */
    public function streamData(string $ref): ?string
    {
        foreach ($this->object($ref) as $entry) {
            if (($entry[0] ?? null) !== 'stream') {
                continue;
            }

            $decoded = $entry[3] ?? null;
            if (is_array($decoded) && is_string($decoded[0] ?? null)) {
                return ($decoded[1] ?? []) === [] ? $decoded[0] : null;
            }

            return is_string($entry[1] ?? null) ? $entry[1] : null;
        }

        return null;
    }

    /**
     * Turn a `['<<', [...]]` entry into an associative array of name => raw entry.
     *
     * @param  array<int, mixed>  $entry
     * @return array<string, array<int, mixed>>
     */
    public function decodeDictionary(array $entry): array
    {
        $items = $entry[1] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        $count = count($items);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $key = $items[$i];
            if (! is_array($key) || ($key[0] ?? null) !== '/' || ! is_string($key[1] ?? null)) {
                continue;
            }

            $value = $items[$i + 1];
            if (is_array($value)) {
                $out[$key[1]] = $value;
            }
        }

        return $out;
    }

    /**
     * Follow indirect references until a direct object is reached.
     *
     * @param  array<int, mixed>|null  $entry
     * @return array<int, mixed>|null
     */
    public function resolve(?array $entry): ?array
    {
        $depth = 0;
        while (is_array($entry) && ($entry[0] ?? null) === 'objref') {
            $ref = $entry[1];
            if (! is_string($ref) || isset($this->resolving[$ref]) || ++$depth > 64) {
                return null;
            }

            $this->resolving[$ref] = true;
            $object = $this->object($ref);
            unset($this->resolving[$ref]);

            $entry = null;
            foreach ($object as $candidate) {
                if (($candidate[0] ?? null) !== 'endobj') {
                    $entry = $candidate;
                    break;
                }
            }
        }

        return $entry;
    }

    /**
     * Resolve a dictionary entry to an associative dictionary.
     *
     * @param  array<string, array<int, mixed>>  $dict
     * @return array<string, array<int, mixed>>|null
     */
    public function dictEntryAsDictionary(array $dict, string $key): ?array
    {
        $entry = $this->resolve($dict[$key] ?? null);
        if (! is_array($entry) || ($entry[0] ?? null) !== '<<') {
            return null;
        }

        return $this->decodeDictionary($entry);
    }

    /**
     * Resolve a dictionary entry to a list of raw entries.
     *
     * @param  array<string, array<int, mixed>>  $dict
     * @return array<int, array<int, mixed>>|null
     */
    public function dictEntryAsArray(array $dict, string $key): ?array
    {
        $entry = $this->resolve($dict[$key] ?? null);
        if (! is_array($entry) || ($entry[0] ?? null) !== '[' || ! is_array($entry[1] ?? null)) {
            return null;
        }

        /** @var array<int, array<int, mixed>> $items */
        $items = array_values(array_filter($entry[1], 'is_array'));

        return $items;
    }

    /**
     * @param  array<string, array<int, mixed>>  $dict
     */
    public function dictEntryAsName(array $dict, string $key): ?string
    {
        $entry = $this->resolve($dict[$key] ?? null);
        if (is_array($entry) && ($entry[0] ?? null) === '/' && is_string($entry[1] ?? null)) {
            return $entry[1];
        }

        return null;
    }

    /**
     * @param  array<string, array<int, mixed>>  $dict
     */
    public function dictEntryAsFloat(array $dict, string $key): ?float
    {
        $entry = $this->resolve($dict[$key] ?? null);
        if (is_array($entry) && ($entry[0] ?? null) === 'numeric' && is_numeric($entry[1] ?? null)) {
            return (float) $entry[1];
        }

        return null;
    }

    /**
     * @param  array<string, array<int, mixed>>  $dict
     */
    public function dictEntryAsInt(array $dict, string $key): ?int
    {
        $value = $this->dictEntryAsFloat($dict, $key);

        return $value === null ? null : (int) round($value);
    }

    /**
     * A numeric array such as a page box or a /Widths list.
     *
     * @param  array<string, array<int, mixed>>  $dict
     * @return array<int, float>|null
     */
    public function dictEntryAsNumbers(array $dict, string $key): ?array
    {
        $items = $this->dictEntryAsArray($dict, $key);
        if ($items === null) {
            return null;
        }

        $numbers = [];
        foreach ($items as $item) {
            $resolved = $this->resolve($item);
            if (! is_array($resolved) || ($resolved[0] ?? null) !== 'numeric' || ! is_numeric($resolved[1] ?? null)) {
                return null;
            }

            $numbers[] = (float) $resolved[1];
        }

        return $numbers;
    }

    /**
     * The referenced object key for a dictionary entry, if the entry is a reference.
     *
     * @param  array<string, array<int, mixed>>  $dict
     */
    public function dictEntryRef(array $dict, string $key): ?string
    {
        $entry = $dict[$key] ?? null;
        if (is_array($entry) && ($entry[0] ?? null) === 'objref' && is_string($entry[1] ?? null)) {
            return $entry[1];
        }

        return null;
    }

    /**
     * Concatenated, decoded content streams for a page /Contents entry.
     *
     * ISO 32000-1 7.8.2: an array of streams is treated as a single stream with the
     * parts joined by whitespace, so a lexical token may not straddle the boundary.
     *
     * @param  array<string, array<int, mixed>>  $pageDict
     */
    public function contentStream(array $pageDict): string
    {
        $entry = $pageDict['Contents'] ?? null;
        if (! is_array($entry)) {
            return '';
        }

        if (($entry[0] ?? null) === 'objref') {
            $ref = $entry[1];
            if (is_string($ref)) {
                $direct = $this->streamData($ref);
                if ($direct !== null) {
                    return $direct;
                }
            }
        }

        $resolved = $this->resolve($entry);
        if (is_array($resolved) && ($resolved[0] ?? null) === '[' && is_array($resolved[1] ?? null)) {
            $parts = [];
            foreach ($resolved[1] as $item) {
                if (is_array($item) && ($item[0] ?? null) === 'objref' && is_string($item[1] ?? null)) {
                    $parts[] = $this->streamData($item[1]) ?? '';
                }
            }

            return implode("\n", $parts);
        }

        return '';
    }
}
