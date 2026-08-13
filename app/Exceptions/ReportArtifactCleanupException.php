<?php

namespace App\Exceptions;

use RuntimeException;

class ReportArtifactCleanupException extends RuntimeException
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $phase,
        public readonly string $errorCode,
        public readonly ?string $exceptionClass = null,
    ) {
        parent::__construct("Report artifact cleanup failed during [{$phase}] on disk [{$disk}] for [{$path}] ({$errorCode}).");
    }
}
