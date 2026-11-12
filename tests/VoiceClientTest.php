<?php

namespace Vois\Tests;

use GuzzleHttp\Psr7\Response;
use Vois\Exception\VoiceException;
use Vois\Exception\VoisException;
use Vois\Voice\VoiceClient;

final class VoiceClientTest extends TestCase
{
    private const ID = '9aeba619-e656-4afd-aacc-ba64dcf24aef';

    private function client(array $responses): VoiceClient
    {
        return new VoiceClient('vois_2361_abc', ['handler' => $this->handler($responses)]);
    }

    private function ok(mixed $data, array $meta = [], int $status = 200): Response
    {
        return $this->json($status, ['data' => $data, 'meta' => (object) $meta]);
    }

    public function test_call_posts_to_calls_with_bearer_and_returns_call_id(): void
    {
        $v = $this->client([$this->ok(['call_id' => self::ID, 'status' => 'ringing_extension'], [], 202)]);

        $r = $v->call('101', '05321234567', ['reference' => 'musteri-4521']);

        $this->assertSame(['call_id' => self::ID, 'status' => 'ringing_extension'], $r);
        $req = $this->lastRequest();
        $this->assertSame(['POST', 'https://api.vois.com.tr/v1/voice/calls'], [$req->getMethod(), (string) $req->getUri()]);
        $this->assertSame('Bearer vois_2361_abc', $req->getHeaderLine('Authorization'));
        $this->assertSame(['extension' => '101', 'number' => '05321234567', 'reference' => 'musteri-4521'], $this->lastBody());
    }

    public function test_call_options_are_whitelisted(): void
    {
        $v = $this->client([$this->ok(['call_id' => self::ID, 'status' => 'ringing_extension'], [], 202)]);
        $v->call('101', '0532', ['caller_id_number' => '902124189159', 'auto_answer' => true, 'bogus' => 1]);
        $this->assertSame(['extension' => '101', 'number' => '0532', 'caller_id_number' => '902124189159', 'auto_answer' => true], $this->lastBody());
    }

    public function test_hangup_returns_true_and_false_on_404(): void
    {
        $v = $this->client([
            $this->ok(['hung_up' => true]),
            $this->json(404, ['error' => 'not_found', 'message' => 'Süren çağrı bulunamadı.']),
        ]);
        $this->assertTrue($v->hangup(self::ID));
        $this->assertSame(['DELETE', '/v1/voice/calls/' . self::ID], [$this->lastRequest()->getMethod(), $this->lastRequest()->getUri()->getPath()]);
        $this->assertFalse($v->hangup(self::ID));
    }

    public function test_read_endpoints(): void
    {
        $v = $this->client([
            $this->ok([['extension' => '101', 'registered' => true]]),
            $this->ok([]),
            $this->ok([['call_id' => self::ID]], ['page' => 1, 'per_page' => 50, 'total' => 1]),
            $this->ok(['call_id' => self::ID]),
        ]);
        $this->assertSame([['extension' => '101', 'registered' => true]], $v->extensions());
        $this->assertSame(['GET', '/v1/voice/extensions'], [$this->lastRequest()->getMethod(), $this->lastRequest()->getUri()->getPath()]);

        $this->assertSame([], $v->activeCalls());
        $this->assertSame('/v1/voice/calls/active', $this->lastRequest()->getUri()->getPath());

        $page = $v->cdr('2026-11-01', '2026-11-12', ['direction' => 'outbound', 'per_page' => 50]);
        $this->assertSame([[['call_id' => self::ID]], ['page' => 1, 'per_page' => 50, 'total' => 1]], [$page['data'], $page['meta']]);
        parse_str($this->lastRequest()->getUri()->getQuery(), $q);
        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-12', 'direction' => 'outbound', 'per_page' => '50'], $q);

        $this->assertSame(['call_id' => self::ID], $v->cdrShow(self::ID));
        $this->assertSame('/v1/voice/cdr/' . self::ID, $this->lastRequest()->getUri()->getPath());
    }

    public function test_cdr_all_walks_every_page(): void
    {
        $v = $this->client([
            $this->ok([['call_id' => 'a'], ['call_id' => 'b']], ['page' => 1, 'per_page' => 2, 'total' => 3]),
            $this->ok([['call_id' => 'c']], ['page' => 2, 'per_page' => 2, 'total' => 3]),
        ]);
        $ids = array_column(iterator_to_array($v->cdrAll('2026-11-01', '2026-11-12', ['per_page' => 2]), false), 'call_id');
        $this->assertSame(['a', 'b', 'c'], $ids);
        $this->assertCount(2, $this->history);
        parse_str($this->lastRequest()->getUri()->getQuery(), $q);
        $this->assertSame('2', $q['page']);
    }

    public function test_download_recording_writes_file(): void
    {
        $v = $this->client([new Response(200, ['Content-Type' => 'audio/wav'], 'RIFFxxxxWAVE')]);
        $path = sys_get_temp_dir() . '/vois-test-' . bin2hex(random_bytes(4)) . '.wav';
        try {
            $this->assertSame($path, $v->downloadRecording(self::ID, $path));
            $this->assertSame('RIFFxxxxWAVE', file_get_contents($path));
            $this->assertSame('/v1/voice/recordings/' . self::ID, $this->lastRequest()->getUri()->getPath());
        } finally {
            @unlink($path);
        }
    }

    public function test_download_recording_into_directory_uses_server_filename_and_returns_path(): void
    {
        // Recordings are WAV when fresh and MP3 after the nightly compression: the server's filename tells which.
        $v = $this->client([new Response(200, ['Content-Type' => 'audio/mpeg', 'Content-Disposition' => 'attachment; filename=cagri-' . self::ID . '.mp3'], 'ID3xxxx')]);
        $dir = sys_get_temp_dir() . '/vois-dir-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $saved = $v->downloadRecording(self::ID, $dir);
            $this->assertSame($dir . '/cagri-' . self::ID . '.mp3', $saved);
            $this->assertSame('ID3xxxx', file_get_contents($saved));
            $this->assertSame(['cagri-' . self::ID . '.mp3'], array_values(array_diff(scandir($dir), ['.', '..'])));
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    public function test_download_recording_into_directory_falls_back_to_content_type(): void
    {
        $v = $this->client([new Response(200, ['Content-Type' => 'audio/x-wav'], 'RIFF')]);
        $dir = sys_get_temp_dir() . '/vois-dir-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->assertSame($dir . '/cagri-' . self::ID . '.wav', $v->downloadRecording(self::ID, $dir));
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    public function test_download_recording_404_throws_and_leaves_no_file(): void
    {
        $v = $this->client([$this->json(404, ['error' => 'not_found', 'message' => 'Kayıt bulunamadı.'])]);
        $path = sys_get_temp_dir() . '/vois-test-' . bin2hex(random_bytes(4)) . '.wav';
        try {
            $v->downloadRecording(self::ID, $path);
            $this->fail('exception expected');
        } catch (VoiceException $e) {
            $this->assertSame(['not_found', 404], [$e->errorCode, $e->httpStatus]);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_errors_map_to_voice_exception(): void
    {
        $v = $this->client([$this->json(409, ['error' => 'extension_unavailable', 'message' => 'Dahili şu an bağlı değil (telefon kayıtlı değil).'])]);
        try {
            $v->call('102', '0532');
            $this->fail('exception expected');
        } catch (VoiceException $e) {
            $this->assertSame(['extension_unavailable', 409, 'Dahili şu an bağlı değil (telefon kayıtlı değil).'], [$e->errorCode, $e->httpStatus, $e->getMessage()]);
        }
    }

    public function test_call_id_is_validated_before_request(): void
    {
        $v = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $v->cdrShow('../../etc/passwd');
    }

    public function test_non_json_error_is_vois_exception(): void
    {
        $v = $this->client([new Response(500, [], 'oops')]);
        try {
            $v->extensions();
            $this->fail('exception expected');
        } catch (VoiceException $e) {
            $this->fail('should be plain VoisException');
        } catch (VoisException $e) {
            $this->assertSame(500, $e->httpStatus);
        }
    }

    public function test_x_api_key_auth_option(): void
    {
        $v = new VoiceClient('vois_k', ['handler' => $this->handler([$this->ok([])]), 'auth_header' => 'x-api-key']);
        $v->extensions();
        $this->assertSame(['vois_k', ''], [$this->lastRequest()->getHeaderLine('X-API-Key'), $this->lastRequest()->getHeaderLine('Authorization')]);
    }
}
