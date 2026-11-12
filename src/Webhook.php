<?php

namespace Vois;

use Vois\Exception\InvalidSignatureException;

/**
 * Verify a VOIS webhook request. Docs: https://docs.vois.com.tr/voice/#imza
 *
 *   $event = Webhook::verify(file_get_contents('php://input'), $_SERVER['HTTP_X_VOIS_SIGNATURE'] ?? null, $secret);
 *
 * Checks the HMAC-SHA256 signature (constant-time) and that `fired_at` is within $toleranceSeconds
 * of now (replay protection; 0 disables). Store $event['id'] to ignore duplicates.
 */
final class Webhook
{
    /** @return array{id?: string, event: string, data: mixed, fired_at?: string} */
    public static function verify(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSeconds = 300, ?int $now = null): array
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Webhook gizli anahtarı boş (VOIS_WEBHOOK_SECRET).');
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        if ($signatureHeader === null || ! hash_equals($expected, trim($signatureHeader))) {
            throw new InvalidSignatureException('Webhook imzası geçersiz.', 401);
        }
        $event = json_decode($rawBody, true);
        if (! is_array($event) || ! isset($event['event'])) {
            throw new InvalidSignatureException('Webhook gövdesi okunamadı.', 400);
        }
        if ($toleranceSeconds > 0) {
            $fired = isset($event['fired_at']) ? strtotime((string) $event['fired_at']) : false;
            if ($fired === false || abs(($now ?? time()) - $fired) > $toleranceSeconds) {
                throw new InvalidSignatureException('Webhook olayı çok eski ya da zamanı geçersiz (tekrar gönderim olabilir).', 401);
            }
        }

        return $event;
    }
}
