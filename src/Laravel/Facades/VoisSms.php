<?php

namespace Vois\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static int sendSingle(string $number, string $content, string $sender, string $title, array $extra = [])
 * @method static int sendBulk(array $numbers, string $content, string $sender, string $title, array $extra = [])
 * @method static int sendPersonalized(array $messages, string $sender, string $title, array $extra = [])
 * @method static int sendOtp(string $number, string $content, string $sender, array $extra = [])
 * @method static array send(array $payload)
 * @method static array cancel(?int $pkgId = null, ?string $customId = null)
 * @method static array packages(string $startDate, string $finishDate, array $filters = [], int $pageIndex = 0, int $pageSize = 50)
 * @method static array packageItems(array $filter, int $pageIndex = 0, int $pageSize = 100)
 * @method static array summary(string $startDate, string $finishDate)
 * @method static array inquiry(string $gsm, array $filters = [])
 * @method static int|float credit()
 * @method static array senders(int $pageIndex = 0, int $pageSize = 100, array $filters = [])
 * @method static array gateways()
 * @method static array inbound(array $filters = [], int $pageIndex = 0, int $pageSize = 100)
 *
 * @see \Vois\Sms\SmsClient
 */
final class VoisSms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vois\Sms\SmsClient::class;
    }
}
