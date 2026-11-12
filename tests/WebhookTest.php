<?php

namespace Vois\Tests;

use Vois\Exception\InvalidSignatureException;
use Vois\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'gizli-anahtar';

    private function body(string $firedAt = '2026-11-12T10:00:00+03:00'): string
    {
        return json_encode(['id' => '3b1f6c2e-8d4a-4f0e-9a51-2c7e0b9d6a14', 'event' => 'call.ringing',
            'data' => ['call_id' => 'x', 'from' => '905321234567'], 'fired_at' => $firedAt], JSON_UNESCAPED_UNICODE);
    }

    private function sig(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function test_valid_signature_returns_decoded_event(): void
    {
        $b = $this->body();
        $now = strtotime('2026-11-12T10:01:00+03:00');
        $e = Webhook::verify($b, $this->sig($b), self::SECRET, 300, $now);
        $this->assertSame(['call.ringing', 'x'], [$e['event'], $e['data']['call_id']]);
    }

    public function test_wrong_secret_missing_or_malformed_header_rejected(): void
    {
        $b = $this->body();
        $now = strtotime('2026-11-12T10:00:00+03:00');
        foreach ([$this->sig($b, 'baska'), null, '', 'sha1=abc', hash_hmac('sha256', $b, self::SECRET)] as $bad) {
            try {
                Webhook::verify($b, $bad, self::SECRET, 300, $now);
                $this->fail('rejected expected for ' . var_export($bad, true));
            } catch (InvalidSignatureException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_tampered_body_rejected(): void
    {
        $b = $this->body();
        $this->expectException(InvalidSignatureException::class);
        Webhook::verify(str_replace('905321234567', '905000000000', $b), $this->sig($b), self::SECRET, 300, strtotime('2026-11-12T10:00:00+03:00'));
    }

    public function test_stale_event_rejected_and_tolerance_can_be_disabled(): void
    {
        $b = $this->body('2026-11-12T10:00:00+03:00');
        $late = strtotime('2026-11-12T10:06:00+03:00');
        try {
            Webhook::verify($b, $this->sig($b), self::SECRET, 300, $late);
            $this->fail('stale expected');
        } catch (InvalidSignatureException $e) {
            $this->assertStringContainsString('eski', $e->getMessage());
        }
        $this->assertSame('call.ringing', Webhook::verify($b, $this->sig($b), self::SECRET, 0, $late)['event']);
    }

    public function test_future_skew_beyond_tolerance_rejected(): void
    {
        $b = $this->body('2026-11-12T10:10:00+03:00');
        $this->expectException(InvalidSignatureException::class);
        Webhook::verify($b, $this->sig($b), self::SECRET, 300, strtotime('2026-11-12T10:00:00+03:00'));
    }

    public function test_signed_but_not_json_rejected(): void
    {
        $b = 'not json';
        $this->expectException(InvalidSignatureException::class);
        Webhook::verify($b, $this->sig($b), self::SECRET, 0);
    }

    public function test_empty_secret_is_a_config_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Webhook::verify($this->body(), 'sha256=x', '');
    }
}
