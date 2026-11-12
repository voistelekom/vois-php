<?php

namespace Vois\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Vois\Exception\SmsException;
use Vois\Exception\VoisException;
use Vois\Sms\SmsClient;

final class SmsClientTest extends TestCase
{
    private function client(array $responses): SmsClient
    {
        return new SmsClient('kullanici', 'sifre', ['handler' => $this->handler($responses)]);
    }

    private function ok(array $data): Response
    {
        return $this->json(200, ['data' => $data, 'err' => null]);
    }

    public function test_send_single_posts_documented_body_with_basic_auth(): void
    {
        $sms = $this->client([$this->ok(['pkgID' => 48213377])]);

        $id = $sms->sendSingle('905321234567', 'Merhaba', 'BASLIK', 'Randevu hatirlatma', ['encoding' => 1, 'customID' => 'r-1']);

        $this->assertSame(48213377, $id);
        $r = $this->lastRequest();
        $this->assertSame('POST', $r->getMethod());
        $this->assertSame('https://api.vois.com.tr/sms/create', (string) $r->getUri());
        $this->assertSame('Basic ' . base64_encode('kullanici:sifre'), $r->getHeaderLine('Authorization'));
        $this->assertStringContainsString('application/json', $r->getHeaderLine('Content-Type'));
        $this->assertSame([
            'type' => 1, 'sendingType' => 0, 'title' => 'Randevu hatirlatma', 'sender' => 'BASLIK',
            'number' => '905321234567', 'content' => 'Merhaba', 'encoding' => 1, 'customID' => 'r-1',
        ], $this->lastBody());
    }

    public function test_send_bulk_and_personalized_set_sending_type(): void
    {
        $sms = $this->client([$this->ok(['pkgID' => 1]), $this->ok(['pkgID' => 2])]);

        $this->assertSame(1, $sms->sendBulk(['905321234567', '905441234567'], 'Kampanya', 'BASLIK', 'Kasim kampanyasi', ['commercial' => true]));
        $b = $this->lastBody();
        $this->assertSame([1, 1, ['905321234567', '905441234567'], true], [$b['type'], $b['sendingType'], $b['numbers'], $b['commercial']]);

        $msgs = [['nr' => '905321234567', 'msg' => 'Ayşe Hanım', 'xid' => 'F-1']];
        $this->assertSame(2, $sms->sendPersonalized($msgs, 'BASLIK', 'Fatura bildirimi'));
        $b = $this->lastBody();
        $this->assertSame([2, $msgs], [$b['sendingType'], $b['numbers']]);
        $this->assertArrayNotHasKey('content', $b);
    }

    public function test_send_otp_uses_otp_endpoint(): void
    {
        $sms = $this->client([$this->ok(['pkgID' => 48213415])]);

        $this->assertSame(48213415, $sms->sendOtp('905321234567', 'Kodunuz: 482915', 'BASLIK', ['validity' => 3]));
        $this->assertSame('/sms/create-otp', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['number' => '905321234567', 'sender' => 'BASLIK', 'content' => 'Kodunuz: 482915', 'validity' => 3], $this->lastBody());
    }

    public function test_send_raw_payload_returns_data(): void
    {
        $sms = $this->client([$this->ok(['pkgID' => 9])]);
        $this->assertSame(['pkgID' => 9], $sms->send(['type' => 4, 'sendingType' => 1]));
        $this->assertSame(['type' => 4, 'sendingType' => 1], $this->lastBody());
    }

    public function test_cancel_by_id_or_custom_id(): void
    {
        $sms = $this->client([$this->ok(['status' => 0]), $this->ok(['status' => 0])]);
        $sms->cancel(48213390);
        $this->assertSame(['/sms/cancel', ['id' => 48213390]], [$this->lastRequest()->getUri()->getPath(), $this->lastBody()]);
        $sms->cancel(null, 'kampanya-7');
        $this->assertSame(['customID' => 'kampanya-7'], $this->lastBody());
    }

    public function test_cancel_requires_an_identifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([])->cancel();
    }

    public function test_reports_and_account_endpoints(): void
    {
        $list = ['stats' => ['totalRecord' => 1], 'list' => [['id' => 1]]];
        $sms = $this->client([
            $this->ok($list), $this->ok($list), $this->ok(['total' => 5]), $this->ok($list),
            $this->ok(['credit' => 25400]), $this->ok($list), $this->ok($list), $this->ok($list),
        ]);

        $this->assertSame($list, $sms->packages('2026-11-01 00:00', '2026-11-10 23:59', ['customIDs' => ['a']], 0, 50));
        $this->assertSame(['/sms/list', ['customIDs' => ['a'], 'startDate' => '2026-11-01 00:00', 'finishDate' => '2026-11-10 23:59', 'pageIndex' => 0, 'pageSize' => 50]],
            [$this->lastRequest()->getUri()->getPath(), $this->lastBody()]);

        $sms->packageItems(['pkgID' => 48213402], 0, 100);
        $this->assertSame(['/sms/list-item', ['pkgID' => 48213402, 'pageIndex' => 0, 'pageSize' => 100]], [$this->lastRequest()->getUri()->getPath(), $this->lastBody()]);

        $this->assertSame(['total' => 5], $sms->summary('2026-11-01 00:00', '2026-11-30 23:59'));
        $this->assertSame('/sms/summary', $this->lastRequest()->getUri()->getPath());

        $sms->inquiry('905321234567');
        $this->assertSame(['/sms/inquiry-number', ['gsm' => '905321234567']], [$this->lastRequest()->getUri()->getPath(), $this->lastBody()]);

        $this->assertSame(25400, $sms->credit());
        $this->assertSame(['GET', '/user/credit'], [$this->lastRequest()->getMethod(), $this->lastRequest()->getUri()->getPath()]);

        $sms->senders();
        $this->assertSame(['/sms/list-sender', ['pageIndex' => 0, 'pageSize' => 100]], [$this->lastRequest()->getUri()->getPath(), $this->lastBody()]);

        $sms->gateways();
        $this->assertSame(['GET', '/sms/list-gateway'], [$this->lastRequest()->getMethod(), $this->lastRequest()->getUri()->getPath()]);

        $sms->inbound(['keyword' => 'RET'], 0, 100);
        $this->assertSame(['/mo/list-request', ['keyword' => 'RET', 'pageIndex' => 0, 'pageSize' => 100]], [$this->lastRequest()->getUri()->getPath(), $this->lastBody()]);
    }

    public function test_api_error_becomes_sms_exception_with_code(): void
    {
        $sms = $this->client([$this->json(417, ['data' => null, 'err' => ['status' => 417, 'code' => 'ERR_USER_CREDIT_REQUIRED', 'message' => 'ERR_USER_CREDIT_REQUIRED']])]);
        try {
            $sms->sendSingle('905321234567', 'x', 'BASLIK', 'Deneme baslik');
            $this->fail('exception expected');
        } catch (SmsException $e) {
            $this->assertSame('ERR_USER_CREDIT_REQUIRED', $e->errorCode);
            $this->assertSame(417, $e->httpStatus);
            $this->assertSame(417, $e->status);
            $this->assertStringContainsString('kredi', $e->getMessage());
        }
    }

    public function test_auth_error_403_with_body_status_401(): void
    {
        $sms = $this->client([$this->json(403, ['data' => null, 'err' => ['status' => 401, 'code' => 'ERR_UNAUTHORIZED_REQUEST', 'message' => 'ERR_UNAUTHORIZED_REQUEST']])]);
        try {
            $sms->credit();
            $this->fail('exception expected');
        } catch (SmsException $e) {
            $this->assertSame(['ERR_UNAUTHORIZED_REQUEST', 403, 401], [$e->errorCode, $e->httpStatus, $e->status]);
        }
    }

    public function test_err_in_200_response_also_throws(): void
    {
        $sms = $this->client([$this->json(200, ['data' => null, 'err' => ['status' => 417, 'code' => 'ERR_INVALID_PARAM', 'message' => 'ERR_INVALID_PARAM']])]);
        $this->expectException(SmsException::class);
        $sms->credit();
    }

    public function test_non_json_and_network_errors_become_vois_exception(): void
    {
        $sms = $this->client([new Response(502, [], '<html>bad gateway</html>')]);
        try {
            $sms->credit();
            $this->fail('exception expected');
        } catch (SmsException $e) {
            $this->fail('should not be SmsException');
        } catch (VoisException $e) {
            $this->assertSame(502, $e->httpStatus);
        }

        $sms = $this->client([new ConnectException('timeout', new Request('GET', '/'))]);
        $this->expectException(VoisException::class);
        $sms->credit();
    }

    public function test_custom_base_uri(): void
    {
        $sms = new SmsClient('u', 'p', ['handler' => $this->handler([$this->ok(['credit' => 1])]), 'base_uri' => 'https://ornek.test/']);
        $sms->credit();
        $this->assertSame('https://ornek.test/user/credit', (string) $this->lastRequest()->getUri());
    }
}
