<?php

namespace Vois\Exception;

/** Base class for every error thrown by this package (network, unexpected response, API errors). */
class VoisException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }
}
