<?php

namespace App\Domain\Incidents\Exceptions;

use RuntimeException;

/**
 * A domain invariant was broken. Rendered as HTTP 409 with its error code (bootstrap/app.php).
 */
class DomainRuleViolation extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
