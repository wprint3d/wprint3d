<?php

namespace App\Gcode;

final readonly class DurationEstimate
{
    public function __construct(
        public int $seconds,
        public string $origin,
        public ?int $metadataSeconds,
        public int $simulatedSeconds,
        public bool $hasUnboundedWait,
        public int $lineCount,
    ) {}
}
