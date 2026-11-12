<?php

namespace Vois\Webhooks;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Vois\Exception\VoisException;
use Vois\Exception\WebhookException;

/**
 * Manage the webhooks created with your API key (scope webhooks:manage). Docs: https://docs.vois.com.tr/voice/#webhook-api
 *
 * A key sees and deletes only the webhooks it created itself.
 * Errors: WebhookException (API error, see $e->errorCode) or VoisException (network / unexpected response).
 */
final class WebhookClient
{
    public const DEFAULT_BASE_URI = 'https://api.vois.com.tr/v1/';

    private ClientInterface $http;

    /** @param array{base_uri?: string, timeout?: float, handler?: callable} $options */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('VOIS API anahtarı gerekli (VOIS_API_KEY).');
        }
        $config = [
            'base_uri'        => rtrim($options['base_uri'] ?? self::DEFAULT_BASE_URI, '/') . '/',
            'timeout'         => $options['timeout'] ?? 30,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => ['Accept' => 'application/json', 'User-Agent' => 'vois-php/1.1.1', 'Authorization' => 'Bearer ' . $apiKey],
        ];
        if (isset($options['handler'])) {
            $config['handler'] = $options['handler'];
        }
        $this->http = new Client($config);
    }

    /** @return list<array{id: int, event: string, url: string, active: bool, created_at: string}> */
    public function list(): array
    {
        return $this->data($this->request('GET', 'webhooks'));
    }

    /**
     * Register a webhook. The returned `secret` is shown ONLY here: store it for Webhook::verify().
     *
     * @return array{id: int, event: string, url: string, secret: string}
     */
    public function create(string $event, string $url): array
    {
        return $this->data($this->request('POST', 'webhooks', ['json' => ['event' => $event, 'url' => $url]]));
    }

    /** Returns false if it does not exist (or belongs to someone else). */
    public function delete(int $id): bool
    {
        try {
            $this->request('DELETE', 'webhooks/' . $id);

            return true;
        } catch (WebhookException $e) {
            if ($e->httpStatus === 404) {
                return false;
            }
            throw $e;
        }
    }

    private function data(array $json): array
    {
        if (! is_array($json['data'])) {
            throw new VoisException("VOIS API'den beklenmeyen yanıt (data alanı yok).");
        }

        return $json['data'];
    }

    private function request(string $method, string $path, array $options = []): array
    {
        try {
            $res = $this->http->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new VoisException("VOIS API'ye ulaşılamadı: " . $e->getMessage(), null, $e);
        }
        $status = $res->getStatusCode();
        $json = json_decode((string) $res->getBody(), true);
        if ($status >= 400) {
            if (is_array($json) && isset($json['error']) && is_string($json['error'])) {
                throw new WebhookException((string) ($json['message'] ?? $json['error']), $json['error'], $status);
            }
            throw new VoisException("VOIS API hata döndürdü (HTTP $status).", $status);
        }
        if ($status === 204) {
            return ['data' => []];
        }
        if (! is_array($json) || ! array_key_exists('data', $json)) {
            throw new VoisException("VOIS API'den beklenmeyen yanıt (HTTP $status).", $status);
        }

        return $json;
    }
}
