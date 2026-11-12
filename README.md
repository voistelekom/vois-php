# VOIS PHP istemcisi

VOIS **SMS** ve **Ses** API'leri için resmi PHP paketi. PHP 8.1+, Laravel 10/11/12 desteği.

- Doküman: https://docs.vois.com.tr/
- Destek: destek@vois.com.tr · 0850 340 6 340

## Kurulum

```bash
composer require vois/vois-php
```

## SMS

API kullanıcı adı ve şifresi SMS hesabınız açılırken VOIS tarafından verilir (panel girişinden farklıdır).

```php
use Vois\Sms\SmsClient;
use Vois\Exception\SmsException;

$sms = new SmsClient('KULLANICI', 'SIFRE');

echo $sms->credit();                       // bağlantı testi, SMS göndermez

$pkgId = $sms->sendSingle('905321234567', 'Randevunuz yarın 10:00', 'BASLIK', 'Randevu hatirlatma', [
    'encoding' => 1,                         // Türkçe karakter
    'customID' => 'randevu-42',              // kendi kayıt numaranız
]);

$sms->sendBulk(['905321234567', '905441234567'], 'Kampanya metni', 'BASLIK', 'Kasim kampanyasi', ['commercial' => true]);
$sms->sendPersonalized([['nr' => '905321234567', 'msg' => 'Sayın Ayşe Hanım…', 'xid' => 'F-1001']], 'BASLIK', 'Fatura bildirimi');
$sms->sendOtp('905321234567', 'Doğrulama kodunuz: 482915', 'BASLIK', ['validity' => 3]);

try {
    $sms->sendSingle('905321234567', 'Merhaba', 'BASLIK', 'Deneme gonderim');
} catch (SmsException $e) {
    $e->errorCode;     // ör. ERR_USER_CREDIT_REQUIRED — programınızda buna göre davranın
    $e->getMessage();  // Türkçe açıklama
}
```

Diğer yöntemler: `send()` (ham gövde, ör. MMS), `cancel()`, `packages()`, `packageItems()`, `summary()`, `inquiry()`, `senders()`, `gateways()`, `inbound()`.

### VOIS API anahtarıyla SMS

SMS kullanıcı adı yerine panelde oluşturduğunuz VOIS API anahtarıyla da gönderebilirsiniz (anahtarda `sms:send` / `sms:read` yetkisi olmalı). Yöntemler aynıdır.

```php
$sms = SmsClient::withApiKey('vois_1234_…');
echo $sms->credit();
try {
    $sms->sendSingle('905321234567', 'Merhaba', 'BASLIK', 'Deneme gonderim');
} catch (SmsException $e) {
    $e->errorCode;     // VOIS tarafı hatalarda: insufficient_scope, sms_not_configured, sms_blocked, rate_limited …
}
```

## Ses (tıkla-ara, çağrı geçmişi, kayıtlar)

Anahtarı VOIS panelinizde oluşturun: **Ayarlar › Açık REST API & Webhook › API & Webhook Yönetimi**.

```php
use Vois\Voice\VoiceClient;
use Vois\Exception\VoiceException;

$voice = new VoiceClient('vois_1234_…');

$voice->extensions();                                         // dahililer, arama yapmaz

try {
    $call = $voice->call('101', '05321234567', ['reference' => 'musteri-4521']);
    $call['call_id'];
} catch (VoiceException $e) {
    $e->errorCode;     // ör. extension_unavailable (telefon bağlı değil), extension_busy
    $e->getMessage();  // API'nin Türkçe açıklaması — kullanıcıya gösterebilirsiniz
}

$voice->hangup($callId);                                      // false: çağrı zaten bitmiş
$page = $voice->cdr('2026-11-01', '2026-11-12', ['direction' => 'inbound']);  // ['data' => [...], 'meta' => [...]]
foreach ($voice->cdrAll('2026-11-01', '2026-11-12') as $row) { /* bütün sayfalar */ }
$voice->cdrShow($callId);
$file = $voice->downloadRecording($callId, '/kayitlar');       // klasör verin: cagri-<id>.wav ya da .mp3 olarak kaydeder
```

## Webhook yönetimi

Anahtarınızla (`webhooks:manage` yetkisi) kendi webhook'larınızı kodla yönetin. Bir anahtar yalnızca kendi oluşturduklarını görür ve siler.

```php
use Vois\Webhooks\WebhookClient;
use Vois\Exception\WebhookException;

$wh = new WebhookClient('vois_1234_…');
try {
    $kayit = $wh->create('call.ended', 'https://siteniz.com/vois/webhook');
    $kayit['secret'];                  // gizli anahtar yalnızca burada görünür; saklayın
} catch (WebhookException $e) {
    $e->errorCode;     // invalid_event, url_not_allowed, https_required, limit_reached …
}
$wh->list();                           // [['id' => 3, 'event' => …, 'url' => …, 'active' => true, …]]
$wh->delete(3);                        // false: bulunamadı
```

## Webhook doğrulama

```php
use Vois\Webhook;
use Vois\Exception\InvalidSignatureException;

try {
    $event = Webhook::verify(file_get_contents('php://input'), $_SERVER['HTTP_X_VOIS_SIGNATURE'] ?? null, 'WEBHOOK_GIZLI_ANAHTARI');
} catch (InvalidSignatureException $e) {
    http_response_code(401);
    exit;
}
// $event['event'] → call.ringing / call.answered / call.ended
// $event['id'] → aynı olayı ikinci kez işlememek için saklayın
```

İmza sabit zamanlı karşılaştırılır; `fired_at` 5 dakikadan eskiyse (tekrar gönderim) reddedilir.

## Laravel

Paket kendini otomatik kaydeder. `.env`:

```dotenv
VOIS_SMS_USERNAME=...
VOIS_SMS_PASSWORD=...
VOIS_API_KEY=vois_...
# SMS kullanıcı adı verilmezse SMS, VOIS_API_KEY (ya da VOIS_SMS_API_KEY) ile gönderilir
VOIS_WEBHOOK_SECRET=...
```

```php
use Vois\Laravel\Facades\VoisSms;
use Vois\Laravel\Facades\VoisVoice;

VoisSms::sendSingle('905321234567', 'Merhaba', 'BASLIK', 'Deneme gonderim');
VoisVoice::call(auth()->user()->dahili, $musteri->telefon, ['reference' => 'musteri-' . $musteri->id]);
```

Webhook rotasını `routes/api.php` içine koyun (orada CSRF kontrolü yoktur; isteği tarayıcı değil VOIS sunucusu gönderir):

```php
Route::post('/vois/webhook', function (Illuminate\Http\Request $request) {
    $event = $request->attributes->get('vois_event');   // imzası doğrulanmış olay
    // ...
    return response()->noContent();
})->middleware('vois.webhook');
```

Ayar dosyasını projenize kopyalamak için: `php artisan vendor:publish --tag=vois-config`.

## Hatalar

| Sınıf | Ne zaman |
|---|---|
| `Vois\Exception\SmsException` | SMS API hata döndürdü (`errorCode`, `status`, `httpStatus`) |
| `Vois\Exception\VoiceException` | Ses API hata döndürdü (`errorCode`, `httpStatus`) |
| `Vois\Exception\WebhookException` | Webhook yönetimi hata döndürdü (`errorCode`, `httpStatus`) |
| `Vois\Exception\InvalidSignatureException` | Webhook imzası geçersiz ya da olay eski |
| `Vois\Exception\VoisException` | Ağ hatası ya da beklenmeyen yanıt (diğerlerinin üst sınıfı) |

Hata kodlarının tam listesi: [SMS](https://docs.vois.com.tr/sms/#hatalar) · [Ses](https://docs.vois.com.tr/voice/#hatalar)

## Lisans

MIT
