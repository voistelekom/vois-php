<?php

namespace Vois\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array list()
 * @method static array create(string $event, string $url)
 * @method static bool delete(int $id)
 *
 * @see \Vois\Webhooks\WebhookClient
 */
final class VoisWebhooks extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vois\Webhooks\WebhookClient::class;
    }
}
