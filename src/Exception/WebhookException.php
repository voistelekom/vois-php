<?php

namespace Vois\Exception;

/**
 * Webhook management API returned an error. Branch on $errorCode
 * (unauthorized, insufficient_scope, invalid_event, url_not_allowed, https_required, limit_reached …);
 * getMessage() is the API's Turkish `message`.
 */
class WebhookException extends VoisException
{
    public function __construct(string $message, public readonly string $errorCode, ?int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
    }
}
