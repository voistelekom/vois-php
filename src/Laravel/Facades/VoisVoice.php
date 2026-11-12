<?php

namespace Vois\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array call(string $extension, string $number, array $options = [])
 * @method static bool hangup(string $callId)
 * @method static array activeCalls()
 * @method static array extensions()
 * @method static array cdr(string $from, string $to, array $filters = [])
 * @method static \Generator cdrAll(string $from, string $to, array $filters = [])
 * @method static array cdrShow(string $callId)
 * @method static string downloadRecording(string $callId, string $path)
 *
 * @see \Vois\Voice\VoiceClient
 */
final class VoisVoice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vois\Voice\VoiceClient::class;
    }
}
