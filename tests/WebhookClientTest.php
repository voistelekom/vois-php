<?php

namespace Vois\Tests;

use GuzzleHttp\Psr7\Response;
use Vois\Exception\VoisException;
use Vois\Exception\WebhookException;
use Vois\Webhooks\WebhookClient;

final class WebhookClientTest extends TestCase
{
    private function client(array $responses): WebhookClient
    {
        return new WebhookClient('vois_2361_abc', ['handler' => $this->handler($responses)]);
    }

    public function test_list_returns_rows(): void
    {
        $row = ['id' => 3, 'event' => 'call.ended', 'url' => 'https://a.example/h', 'active' => true, 'created_at' => '2026-11-12T10:00:00+03:00'];
        $w = $this->client([$this->json(200, ['data' => [$row], 'meta' => ['total' => 1]])]);

        $this->assertSame([$row], $w->list());
        $r = $this->lastRequest();
        $this->assertSame(['GET', 'https://api.vois.com.tr/v1/webhooks'], [$r->getMethod(), (string) $r->getUri()]);
        $this->assertSame('Bearer vois_2361_abc', $r->getHeaderLine('Authorization'));
    }

    public function test_create_posts_and_returns_secret(): void
    {
        $w = $this->client([$this->json(201, ['data' => ['id' => 9, 'event' => 'call.ended', 'url' => 'https://a.example/h', 'secret' => 'whsec_x']])]);

        $r = $w->create('call.ended', 'https://a.example/h');

        $this->assertSame('whsec_x', $r['secret']);
        $req = $this->lastRequest();
        $this->assertSame(['POST', 'https://api.vois.com.tr/v1/webhooks'], [$req->getMethod(), (string) $req->getUri()]);
        $this->assertSame(['event' => 'call.ended', 'url' => 'https://a.example/h'], $this->lastBody());
    }

    public function test_delete_true_on_204_false_on_404(): void
    {
        $w = $this->client([new Response(204), $this->json(404, ['error' => 'not_found', 'message' => 'Yok'])]);

        $this->assertTrue($w->delete(9));
        $r = $this->lastRequest();
        $this->assertSame(['DELETE', 'https://api.vois.com.tr/v1/webhooks/9'], [$r->getMethod(), (string) $r->getUri()]);
        $this->assertFalse($w->delete(10));
    }

    public function test_422_throws_webhook_exception_with_code(): void
    {
        $w = $this->client([$this->json(422, ['error' => 'https_required', 'message' => 'HTTPS gerekli'])]);
        try {
            $w->create('call.ended', 'http://x');
            $this->fail('exception expected');
        } catch (WebhookException $e) {
            $this->assertSame(['https_required', 422, 'HTTPS gerekli'], [$e->errorCode, $e->httpStatus, $e->getMessage()]);
        }
    }

    public function test_401_throws_and_garbage_is_vois_exception(): void
    {
        $w = $this->client([$this->json(401, ['error' => 'unauthorized', 'message' => 'x']), new Response(500, [], 'oops')]);
        try {
            $w->list();
            $this->fail('exception expected');
        } catch (WebhookException $e) {
            $this->assertSame('unauthorized', $e->errorCode);
        }
        $this->expectException(VoisException::class);
        $w->list();
    }

    public function test_empty_key_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebhookClient('');
    }
}
