<?php

namespace Vois\Exception;

/**
 * Voice API returned an error. Branch on $errorCode (e.g. extension_unavailable);
 * getMessage() is the API's Turkish `message`.
 */
class VoiceException extends VoisException
{
    public function __construct(string $message, public readonly string $errorCode, ?int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
    }
}
