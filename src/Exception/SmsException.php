<?php

namespace Vois\Exception;

/**
 * SMS API returned an error (`err` object). Branch on $errorCode (e.g. ERR_USER_CREDIT_REQUIRED);
 * getMessage() is a Turkish description suitable for logs/screens.
 */
class SmsException extends VoisException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly ?int $status, ?int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
    }
}
