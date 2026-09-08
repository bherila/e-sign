<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Preflight;

/** Cost of inspecting one document, recorded so preflight limits can be tuned from data. */
final readonly class DocumentMetrics
{
    public function __construct(
        public int $byteSize,
        public int $pageCount,
        public int $objectCount,
        public float $elapsedSeconds,
        public int $memoryDeltaBytes,
    ) {}

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        return [
            'byte_size' => $this->byteSize,
            'page_count' => $this->pageCount,
            'object_count' => $this->objectCount,
            'elapsed_seconds' => $this->elapsedSeconds,
            'memory_delta_bytes' => $this->memoryDeltaBytes,
        ];
    }
}
