<?php

declare(strict_types=1);

namespace App\Shared\Errors;

use RuntimeException;

/**
 * Base class for errors that map to a public error code and HTTP status. Throw a subclass; the exception renderer
 * turns it into the error envelope for API clients and into the error page for the portal.
 */
abstract class ApplicationException extends RuntimeException
{
    /**
     * Machine-readable code in upper snake case, for example VERSION_CONFLICT.
     */
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /**
     * Extra data for the client. Never secrets, stack traces, SQL or another tenant's data.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [];
    }

    /**
     * Headers to send with the response, for example Retry-After.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [];
    }

    /**
     * The message shown to the client. Defaults to the exception message, so keep that free of internals.
     */
    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
