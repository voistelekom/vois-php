<?php

namespace Vois\Tests;

use Vois\Exception\SmsException;
use Vois\Exception\VoisException;
use Vois\Sms\SmsClient;

final class SmsApiKeyTest extends TestCase
{
    private function client(array $responses): SmsClient
    {
        return SmsClient::withApiKey('vois_2361_abc', ['handler' => $this->handler($responses)]);
    }

    public function test_send_single_uses_v1_sms_path_with_bearer(): void
    {
        $sms = $this->client([$this->json(200, ['data' => ['pkgID' => 7], 'err' => null])]);

        $this->assertSame(7, $sms->sendSingle('905321234567', 'Merhaba', 'BASLIK', 'Deneme'));
        $r = $this->lastRequest();
        $this->assertSame(['POST', 'https://api.vois.com.tr/v1/sms/create'], [$r->getMethod(), (string) $r->getUri()]);
        $this->assertSame('Bearer vois_2361_abc', $r->getHeaderLine('Authorization'));
    }

    public function test_every_method_maps_to_key_paths(): void
    {
        $ok = fn () => $this->json(200, ['data' => ['credit' => 12.5], 'err' => null]);
        $sms = $this->client(array_map($ok, range(1, 10)));
        $calls = [
            [fn () => $sms->credit(), 'GET', 'credit'],
            [fn () => $sms->gateways(), 'GET', 'list-gateway'],
            [fn () => $sms->inbound(), 'POST', 'mo/list-request'],
            [fn () => $sms->sendOtp('9053', 'x', 'B'), 'POST', 'create-otp'],
            [fn () => $sms->cancel(5), 'POST', 'cancel'],
            [fn () => $sms->packages('2026-11-01 00:00', '2026-11-02 00:00'), 'POST', 'list'],
            [fn () => $sms->summary('2026-11-01 00:00', '2026-11-02 00:00'), 'POST', 'summary'],
            [fn () => $sms->inquiry('9053'), 'POST', 'inquiry-number'],
            [fn () => $sms->senders(), 'POST', 'list-sender'],
            [fn () => $sms->packageItems(['pkgID' => 1]), 'POST', 'list-item'],
        ];
        foreach ($calls as [$fn, $method, $path]) {
            try {
                $fn();
            } catch (\Throwable) {
            }
            $r = $this->lastRequest();
            $this->assertSame([$method, 'https://api.vois.com.tr/v1/sms/' . $path], [$r->getMethod(), (string) $r->getUri()]);
        }
    }

    public function test_credit_returns_value(): void
    {
        $this->assertSame(12.5, $this->client([$this->json(200, ['data' => ['credit' => 12.5], 'err' => null])])->credit());
    }

    public function test_vois_style_403_maps_to_sms_exception(): void
    {
        $sms = $this->client([$this->json(403, ['error' => 'sms_not_configured', 'message' => 'SMS bu hesapta tanımlı değil.'])]);
        try {
            $sms->sendSingle('9053', 'x', 'B', 'T');
            $this->fail('exception expected');
        } catch (SmsException $e) {
            $this->assertSame(['sms_not_configured', 403, 'SMS bu hesapta tanımlı değil.'], [$e->errorCode, $e->httpStatus, $e->getMessage()]);
        }
    }

    public function test_provider_error_shape_still_works_with_key(): void
    {
        $sms = $this->client([$this->json(417, ['data' => null, 'err' => ['status' => 417, 'code' => 'ERR_USER_CREDIT_REQUIRED', 'message' => 'x']])]);
        try {
            $sms->credit();
            $this->fail('exception expected');
        } catch (SmsException $e) {
            $this->assertSame('ERR_USER_CREDIT_REQUIRED', $e->errorCode);
        }
    }

    public function test_empty_key_rejected_and_v1_constructor_unchanged(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SmsClient::withApiKey('');
    }

    public function test_legacy_constructor_still_uses_basic_and_old_paths(): void
    {
        $sms = new SmsClient('u', 'p', ['handler' => $this->handler([$this->json(200, ['data' => ['credit' => 1], 'err' => null])])]);
        $sms->credit();
        $this->assertSame('https://api.vois.com.tr/user/credit', (string) $this->lastRequest()->getUri());
    }

    public function test_non_json_is_vois_exception(): void
    {
        $this->expectException(VoisException::class);
        $this->client([new \GuzzleHttp\Psr7\Response(502, [], '<html>')])->credit();
    }
}
