<?php

declare(strict_types=1);

namespace RantaiLabs\Canton;

use RuntimeException;
use Throwable;

/**
 * Thrown when the participant or the token endpoint answers with an error,
 * or cannot be reached at all (then $httpStatus is 0).
 * $errorCode holds the Canton error code, e.g. "KNOWN_PACKAGE_VERSION".
 */
final class LedgerException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
