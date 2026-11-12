<?php

namespace Vois\Exception;

/** Webhook request failed signature or freshness check: reject it (HTTP 401). */
class InvalidSignatureException extends VoisException
{
}
