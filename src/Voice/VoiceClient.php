<?php

namespace Vois\Voice;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Vois\Exception\VoiceException;
use Vois\Exception\VoisException;

/**
 * VOIS Voice API client. Docs: https://docs.vois.com.tr/voice/
 *
 * Errors:
 *  - VoiceException : the API returned an error (check $e->errorCode, e.g. extension_unavailable)
 *  - VoisException  : network error / unexpected response
 */
final class VoiceClient
{
    public const DEFAULT_BASE_URI = 'https://api.vois.com.tr/v1/voice/';

    private const CALL_OPTIONS = ['caller_id_number', 'auto_answer', 'reference'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private ClientInterface $http;

    /**
     * @param string $apiKey key created in the VOIS panel (Ayarlar › Açık REST API & Webhook)
     * @param array{base_uri?: string, timeout?: float, auth_header?: 'bearer'|'x-api-key', handler?: callable} $options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Ses API anahtarı gerekli (VOIS_API_KEY).');
        }
        $auth = ($options['auth_header'] ?? 'bearer') === 'x-api-key'
            ? ['X-API-Key' => $apiKey]
            : ['Authorization' => 'Bearer ' . $apiKey];
        $config = [
            'base_uri'        => rtrim($options['base_uri'] ?? self::DEFAULT_BASE_URI, '/') . '/',
            'timeout'         => $options['timeout'] ?? 30,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => ['Accept' => 'application/json', 'User-Agent' => 'vois-php/1.1.1'] + $auth,
        ];
        if (isset($options['handler'])) {
            $config['handler'] = $options['handler'];
        }
        $this->http = new Client($config);
    }

    // ------------------------------------------------------------------ calls

    /**
     * Click-to-call: rings $extension; when answered, dials $number.
     *
     * @param array{reference?: string, caller_id_number?: string, auto_answer?: bool} $options
     * @return array{call_id: string, status: string}
     */
    public function call(string $extension, string $number, array $options = []): array
    {
        $body = ['extension' => $extension, 'number' => $number] + array_intersect_key($options, array_flip(self::CALL_OPTIONS));

        return $this->request('POST', 'calls', ['json' => $body])['data'];
    }

    /** Hang up a live call. Returns false if the call no longer exists. */
    public function hangup(string $callId): bool
    {
        try {
            return (bool) ($this->request('DELETE', 'calls/' . $this->id($callId))['data']['hung_up'] ?? false);
        } catch (VoiceException $e) {
            if ($e->httpStatus === 404) {
                return false;
            }
            throw $e;
        }
    }

    public function activeCalls(): array
    {
        return $this->request('GET', 'calls/active')['data'];
    }

    public function extensions(): array
    {
        return $this->request('GET', 'extensions')['data'];
    }

    // ------------------------------------------------------------------ history

    /**
     * One page of call history. Dates 'YYYY-MM-DD' (max 31 days).
     * Filters: direction, status, extension, number, page, per_page (≤200).
     *
     * @return array{data: list<array>, meta: array{page?: int, per_page?: int, total?: int}}
     */
    public function cdr(string $from, string $to, array $filters = []): array
    {
        $r = $this->request('GET', 'cdr', ['query' => ['from' => $from, 'to' => $to] + $filters]);

        return ['data' => $r['data'], 'meta' => is_array($r['meta'] ?? null) ? $r['meta'] : []];
    }

    /** Every call in the range, fetching pages as you iterate. */
    public function cdrAll(string $from, string $to, array $filters = []): \Generator
    {
        $page = (int) ($filters['page'] ?? 1);
        while (true) {
            $res = $this->cdr($from, $to, ['page' => $page] + $filters);
            yield from $res['data'];
            $per = (int) ($res['meta']['per_page'] ?? 0);
            $total = (int) ($res['meta']['total'] ?? 0);
            if ($res['data'] === [] || $per <= 0 || $page * $per >= $total) {
                return;
            }
            $filters['page'] = ++$page;
        }
    }

    public function cdrShow(string $callId): array
    {
        return $this->request('GET', 'cdr/' . $this->id($callId))['data'];
    }

    /**
     * Save the call recording and return the saved file's path. Throws VoiceException(not_found) if there is none.
     *
     * Recordings are WAV while fresh and MP3 after the PBX's nightly compression. Pass a DIRECTORY as $path
     * and the file is saved as "cagri-<call_id>.wav|.mp3" with the right extension; pass a file path to choose
     * the name yourself.
     */
    public function downloadRecording(string $callId, string $path): string
    {
        $id = $this->id($callId);
        $tmp = (is_dir($path) ? rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'cagri-' . $id : $path) . '.part';
        try {
            $res = $this->http->request('GET', 'recordings/' . $id, ['sink' => $tmp]);
        } catch (GuzzleException $e) {
            @unlink($tmp);
            throw new VoisException("VOIS Ses API'ye ulaşılamadı: " . $e->getMessage(), null, $e);
        }
        if ($res->getStatusCode() >= 400) {
            $body = (string) @file_get_contents($tmp);
            @unlink($tmp);
            $this->fail($res->getStatusCode(), json_decode($body, true));
        }
        $final = is_dir($path)
            ? rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'cagri-' . $id . '.' . $this->recordingExtension($res)
            : $path;
        if (! @rename($tmp, $final)) {
            @unlink($tmp);
            throw new VoisException("Kayıt dosyası yazılamadı: $final");
        }

        return $final;
    }

    private function recordingExtension(ResponseInterface $res): string
    {
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?[^";]*\.([A-Za-z0-9]{2,5})"?/i', $res->getHeaderLine('Content-Disposition'), $m)) {
            return strtolower($m[1]);
        }

        return str_contains(strtolower($res->getHeaderLine('Content-Type')), 'mpeg') ? 'mp3' : 'wav';
    }

    // ------------------------------------------------------------------ internals

    private function id(string $callId): string
    {
        if (! preg_match(self::UUID, $callId)) {
            throw new \InvalidArgumentException('Geçersiz call_id.');
        }

        return strtolower($callId);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        try {
            $res = $this->http->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new VoisException("VOIS Ses API'ye ulaşılamadı: " . $e->getMessage(), null, $e);
        }

        return $this->decode($res);
    }

    private function decode(ResponseInterface $res): array
    {
        $status = $res->getStatusCode();
        $json = json_decode((string) $res->getBody(), true);
        if ($status >= 400) {
            $this->fail($status, $json);
        }
        if (! is_array($json) || ! array_key_exists('data', $json)) {
            throw new VoisException("VOIS Ses API'den beklenmeyen yanıt (HTTP $status).", $status);
        }

        return $json;
    }

    private function fail(int $status, mixed $json): never
    {
        if (is_array($json) && isset($json['error']) && is_string($json['error'])) {
            throw new VoiceException((string) ($json['message'] ?? $json['error']), $json['error'], $status);
        }
        throw new VoisException("VOIS Ses API hata döndürdü (HTTP $status).", $status);
    }
}
