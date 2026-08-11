<?php

namespace App\Exceptions;

use RuntimeException;

class PrinterSlicingRevisionConflict extends RuntimeException
{
    public function __construct(
        public readonly int $expectedRevision,
        public readonly int $currentRevision,
    ) {
        parent::__construct('The printer slicing configuration changed before this update was applied.');
    }
}
