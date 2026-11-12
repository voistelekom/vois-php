<?php

namespace Vois\Sms;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Vois\Exception\SmsException;
use Vois\Exception\VoisException;

/**
 * VOIS SMS API client. Docs: https://docs.vois.com.tr/sms/
 *
 * Every method returns the response's `data` (as an array) or throws:
 *  - SmsException  : the API returned an error (check $e->errorCode, e.g. ERR_USER_CREDIT_REQUIRED)
 *  - VoisException : network error / unexpected response
 */
final class SmsClient
{
    public const DEFAULT_BASE_URI = 'https://api.vois.com.tr/';
    public const API_KEY_BASE_URI = 'https://api.vois.com.tr/v1/sms/';

    /** Turkish descriptions, same as docs.vois.com.tr/sms/#hatalar */
    private const MESSAGES = [
        'ERR_BAD_REQUEST' => 'İstek hatalı ya da eksik. Gövdeyi ve zorunlu alanları kontrol edin.',
        'ERR_INVALID_PARAM' => 'Bir parametre eksik ya da geçersiz. Zorunlu alanları, tarih biçimini (yyyy-MM-dd HH:mm) ve numara biçimini kontrol edin.',
        'ERR_UNAUTHORIZED_REQUEST' => 'Kullanıcı adı veya şifre hatalı, hesapta IP kısıtı var ya da API yetkisi açık değil.',
        'ERR_USER_NOT_AUTHORIZED' => 'Hesabınızın bu işlem için yetkisi yok (ör. OTP yetkisi kapalı).',
        'ERR_INVALID_SMS_SENDER' => 'Gönderen başlığı hatalı ya da onaylı değil.',
        'ERR_EMPTY_SMS_PACKAGE' => 'Pakette gönderilebilecek geçerli numara kalmadı: numaralar hatalı biçimde, kara listede ya da ticari iletide İYS onayı yok.',
        'ERR_INVALID_SMS_SENDING_DATE' => 'Gönderim tarihi geçersiz ya da geçmişte.',
        'ERR_USER_CREDIT_REQUIRED' => 'Yeterli krediniz yok.',
        'ERR_PARENT_USER_CREDIT_REQUIRED' => 'Bağlı olduğunuz ana hesapta yeterli kredi yok. Bizimle iletişime geçin.',
        'ERR_USER_BRAND_CODE_NOT_FOUND' => 'Ticari gönderim için İYS marka kodunuz tanımlı değil.',
        'ERR_USER_IYS_SETTINGS_NOT_FOUND' => 'Hesabınızda İYS tanımları yok.',
        'ERR_SMS_PKG_CUSTOM_ID_DUPLICATION' => 'Bu customID ile daha önce paket oluşturulmuş.',
        'ERR_SMS_PKG_DUPLICATION' => 'Aynı mesaj bu numaralara son 30 dakika içinde zaten gönderildi.',
        'ERR_INVALID_DATE_RANGE' => 'Tarih aralığı geçersiz.',
        'ERR_SMS_PKG_NOT_FOUND' => 'Paket bulunamadı.',
        'ERR_SMS_PKG_NOT_CHANGEABLE' => 'Paket bu aşamada değiştirilemez/iptal edilemez.',
        'ERR_SMS_PKG_CREATING_FAILED' => 'Paket oluşturulamadı. Tekrar deneyin; sürerse bize bildirin.',
        'ERR_INVALID_PRICING_PLAN' => 'Hesabınızın fiyat planı geçersiz. Bizimle iletişime geçin.',
        'ERR_CREDIT_PLAN_NOT_FOUND' => 'Kredi planı bulunamadı. Bizimle iletişime geçin.',
        'ERR_USER_NOT_FOUND' => 'Kullanıcı bulunamadı.',
        'ERR_MO_NOT_FOUND' => 'Hesabınızda MO kısa numarası tanımlı değil.',
        'ERR_DAILY_REQUEST_LIMIT' => 'Günlük rapor sorgulama sınırı aşıldı.',
        'ERR_CONCURRENT_REQUEST_LIMIT' => 'Aynı anda çok fazla istek gönderildi; istekleri sıraya koyun.',
        'ERR_NOT_FOUND' => 'İstek yapılan adres hatalı.',
        'ERR_SYSTEM_MAINTENANCE' => 'Sistem bakımda; bir süre sonra tekrar deneyin.',
        'ERR_INTERNAL_SERVER' => 'Sunucu hatası; tekrar deneyin, sürerse bize bildirin.',
        'ERR_RATE_LIMIT' => 'Saniyedeki istek sınırı aşıldı; kısa bir süre bekleyip tekrar deneyin.',
        'ERR_SERVICE_UNAVAILABLE' => 'SMS servisine geçici olarak ulaşılamıyor; birkaç dakika sonra tekrar deneyin.',
    ];

    private ClientInterface $http;

    /** true: authenticated with a VOIS API key (Bearer, /v1/sms/ paths). */
    private bool $keyMode = false;

    /**
     * @param array{base_uri?: string, timeout?: float, handler?: callable} $options
     */
    public function __construct(string $username, string $password, array $options = [])
    {
        $apiKey = $options['api_key'] ?? null;
        if ($apiKey === null && ($username === '' || $password === '')) {
            throw new \InvalidArgumentException('SMS API kullanıcı adı ve şifresi gerekli (VOIS_SMS_USERNAME / VOIS_SMS_PASSWORD).');
        }
        $this->keyMode = $apiKey !== null;
        $config = [
            'base_uri'        => rtrim($options['base_uri'] ?? ($this->keyMode ? self::API_KEY_BASE_URI : self::DEFAULT_BASE_URI), '/') . '/',
            'timeout'         => $options['timeout'] ?? 30,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => ['Accept' => 'application/json', 'User-Agent' => 'vois-php/1.1'] + ($this->keyMode ? ['Authorization' => 'Bearer ' . $apiKey] : []),
        ];
        if (! $this->keyMode) {
            $config['auth'] = [$username, $password];
        }
        if (isset($options['handler'])) {
            $config['handler'] = $options['handler'];
        }
        $this->http = new Client($config);
    }

    /**
     * Use a VOIS API key (panel › Ayarlar › Açık REST API & Webhook, scope sms:send / sms:read) instead of
     * the SMS user/password. Same methods; requests go to https://api.vois.com.tr/v1/sms/.
     * VOIS-side errors ({error, message}, e.g. insufficient_scope, sms_not_configured) arrive as SmsException
     * with errorCode = the `error` value.
     *
     * @param array{base_uri?: string, timeout?: float, handler?: callable} $options
     */
    public static function withApiKey(string $apiKey, array $options = []): self
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('VOIS API anahtarı gerekli (VOIS_API_KEY).');
        }

        return new self('', '', ['api_key' => $apiKey] + $options);
    }

    // ------------------------------------------------------------------ sending

    /** Raw POST /sms/create with any documented body (e.g. MMS). Returns data, e.g. ['pkgID' => 123]. */
    public function send(array $payload): array
    {
        return $this->request('POST', 'sms/create', $payload);
    }

    /** One number, one text. Returns the package id (pkgID). Extra fields: encoding, validity, commercial, customID, sendingDate, pushSettings … */
    public function sendSingle(string $number, string $content, string $sender, string $title, array $extra = []): int
    {
        return $this->pkgId($this->send(['type' => 1, 'sendingType' => 0, 'title' => $title, 'sender' => $sender, 'number' => $number, 'content' => $content] + $extra));
    }

    /** Same text to many numbers. Returns pkgID. */
    public function sendBulk(array $numbers, string $content, string $sender, string $title, array $extra = []): int
    {
        return $this->pkgId($this->send(['type' => 1, 'sendingType' => 1, 'title' => $title, 'sender' => $sender, 'numbers' => array_values($numbers), 'content' => $content] + $extra));
    }

    /**
     * Different text per number.
     *
     * @param list<array{nr: string, msg: string, xid?: string}> $messages
     */
    public function sendPersonalized(array $messages, string $sender, string $title, array $extra = []): int
    {
        return $this->pkgId($this->send(['type' => 1, 'sendingType' => 2, 'title' => $title, 'sender' => $sender, 'numbers' => array_values($messages)] + $extra));
    }

    /** Priority one-time-code SMS (POST /sms/create-otp). Returns pkgID. */
    public function sendOtp(string $number, string $content, string $sender, array $extra = []): int
    {
        return $this->pkgId($this->request('POST', 'sms/create-otp', ['number' => $number, 'sender' => $sender, 'content' => $content] + $extra));
    }

    /** Cancel a scheduled package by pkgID or by your customID. */
    public function cancel(?int $pkgId = null, ?string $customId = null): array
    {
        if ($pkgId === null && ($customId === null || $customId === '')) {
            throw new \InvalidArgumentException('İptal için pkgID ya da customID verin.');
        }

        return $this->request('POST', 'sms/cancel', $pkgId !== null ? ['id' => $pkgId] : ['customID' => $customId]);
    }

    // ------------------------------------------------------------------ reports

    /** POST /sms/list. Dates: 'yyyy-MM-dd HH:mm'. Filters: ids, customIDs, keyword, sender, status. */
    public function packages(string $startDate, string $finishDate, array $filters = [], int $pageIndex = 0, int $pageSize = 50): array
    {
        return $this->request('POST', 'sms/list', $filters + ['startDate' => $startDate, 'finishDate' => $finishDate, 'pageIndex' => $pageIndex, 'pageSize' => $pageSize]);
    }

    /** POST /sms/list-item. $filter needs pkgID or customID; optional target, operator, state. */
    public function packageItems(array $filter, int $pageIndex = 0, int $pageSize = 100): array
    {
        return $this->request('POST', 'sms/list-item', $filter + ['pageIndex' => $pageIndex, 'pageSize' => $pageSize]);
    }

    public function summary(string $startDate, string $finishDate): array
    {
        return $this->request('POST', 'sms/summary', ['startDate' => $startDate, 'finishDate' => $finishDate]);
    }

    /** Messages sent to one number. Filters: sender, startDate, finishDate. */
    public function inquiry(string $gsm, array $filters = []): array
    {
        return $this->request('POST', 'sms/inquiry-number', ['gsm' => $gsm] + $filters);
    }

    // ------------------------------------------------------------------ account

    /** Remaining credit. Harmless: good first call to test credentials. */
    public function credit(): int|float
    {
        return $this->request('GET', 'user/credit')['credit'] ?? 0;
    }

    /** Sender titles; only status 1 (approved) can be used. */
    public function senders(int $pageIndex = 0, int $pageSize = 100, array $filters = []): array
    {
        return $this->request('POST', 'sms/list-sender', $filters + ['pageIndex' => $pageIndex, 'pageSize' => $pageSize]);
    }

    public function gateways(): array
    {
        return $this->request('GET', 'sms/list-gateway');
    }

    /** Inbound (MO) messages to your short number. Filters: keyword, startDate, finishDate. */
    public function inbound(array $filters = [], int $pageIndex = 0, int $pageSize = 100): array
    {
        return $this->request('POST', 'mo/list-request', $filters + ['pageIndex' => $pageIndex, 'pageSize' => $pageSize]);
    }

    // ------------------------------------------------------------------ internals

    private function pkgId(array $data): int
    {
        if (! isset($data['pkgID'])) {
            throw new VoisException('Yanıtta paket numarası (pkgID) yok.');
        }

        return (int) $data['pkgID'];
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->keyMode) {
            $path = $path === 'user/credit' ? 'credit' : (str_starts_with($path, 'sms/') ? substr($path, 4) : $path);
        }
        try {
            $res = $this->http->request($method, $path, $body === null ? [] : ['json' => $body]);
        } catch (GuzzleException $e) {
            throw new VoisException("VOIS SMS API'ye ulaşılamadı: " . $e->getMessage(), null, $e);
        }
        $status = $res->getStatusCode();
        $json = json_decode((string) $res->getBody(), true);
        if (! is_array($json)) {
            throw new VoisException("VOIS SMS API'den beklenmeyen yanıt (HTTP $status).", $status);
        }
        if (isset($json['error']) && is_string($json['error'])) {
            throw new SmsException((string) ($json['message'] ?? $json['error']), $json['error'], null, $status);
        }
        if (! empty($json['err']) && is_array($json['err'])) {
            $code = (string) ($json['err']['code'] ?? 'ERR_UNKNOWN');
            $msg = self::MESSAGES[$code] ?? (string) ($json['err']['message'] ?? $code);
            throw new SmsException($msg, $code, isset($json['err']['status']) ? (int) $json['err']['status'] : null, $status);
        }
        if ($status >= 400) {
            throw new VoisException("VOIS SMS API hata döndürdü (HTTP $status).", $status);
        }
        $data = $json['data'] ?? [];

        return is_array($data) ? $data : ['value' => $data];
    }
}
