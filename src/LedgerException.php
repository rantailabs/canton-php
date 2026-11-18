<?php

declare(strict_types=1);

namespace RantaiLabs\Canton;

use RuntimeException;

/**
 * Thrown when the participant answers with an HTTP error.
 * $errorCode holds the Canton error code, e.g. "KNOWN_PACKAGE_VERSION".
 */
final class LedgerException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
