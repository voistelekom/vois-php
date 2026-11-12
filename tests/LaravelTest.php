<?php

namespace Vois\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Testbench;
use Vois\Laravel\Facades\VoisSms;
use Vois\Laravel\Facades\VoisVoice;
use Vois\Laravel\Facades\VoisWebhooks;
use Vois\Laravel\VoisServiceProvider;
use Vois\Sms\SmsClient;
use Vois\Voice\VoiceClient;
use Vois\Webhooks\WebhookClient;

final class LaravelTest extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [VoisServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['VoisSms' => VoisSms::class, 'VoisVoice' => VoisVoice::class, 'VoisWebhooks' => VoisWebhooks::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('vois.sms.username', 'smsuser');
        $app['config']->set('vois.sms.password', 'smspass');
        $app['config']->set('vois.voice.api_key', 'vois_1_key');
        $app['config']->set('vois.webhook.secret', 'whsecret');
    }

    protected function defineRoutes($router): void
    {
        $router->post('/vois/webhook', fn (Request $r) => response()->json(['got' => $r->attributes->get('vois_event')['event'] ?? null]))
            ->middleware('vois.webhook');
    }

    public function test_clients_are_bound_from_config(): void
    {
        $this->assertInstanceOf(SmsClient::class, app(SmsClient::class));
        $this->assertInstanceOf(VoiceClient::class, app(VoiceClient::class));
        $this->assertSame(app(SmsClient::class), app(SmsClient::class));
        $this->assertInstanceOf(SmsClient::class, VoisSms::getFacadeRoot());
        $this->assertInstanceOf(VoiceClient::class, VoisVoice::getFacadeRoot());
        $this->assertInstanceOf(WebhookClient::class, VoisWebhooks::getFacadeRoot());
    }

    public function test_sms_api_key_binds_key_client_when_no_username(): void
    {
        config(['vois.sms.username' => null, 'vois.sms.password' => null, 'vois.sms.api_key' => 'vois_1_key']);
        app()->forgetInstance(SmsClient::class);
        $sms = app(SmsClient::class);
        $this->assertInstanceOf(SmsClient::class, $sms);
        $ref = new \ReflectionProperty($sms, 'keyMode');
        $this->assertTrue($ref->getValue($sms));
    }

    public function test_sms_credentials_win_over_api_key_and_webhook_client_is_bound(): void
    {
        config(['vois.sms.api_key' => 'vois_1_key']);
        app()->forgetInstance(SmsClient::class);
        $ref = new \ReflectionProperty(app(SmsClient::class), 'keyMode');
        $this->assertFalse($ref->getValue(app(SmsClient::class)));
        $this->assertInstanceOf(WebhookClient::class, app(WebhookClient::class));
    }

    public function test_default_config_reads_env_names(): void
    {
        $cfg = require __DIR__ . '/../config/vois.php';
        $this->assertSame(['sms', 'voice', 'webhook'], array_keys($cfg));
        $src = file_get_contents(__DIR__ . '/../config/vois.php');
        foreach (['VOIS_SMS_USERNAME', 'VOIS_SMS_PASSWORD', 'VOIS_API_KEY', 'VOIS_SMS_API_KEY', 'VOIS_WEBHOOK_SECRET'] as $env) {
            $this->assertStringContainsString($env, $src);
        }
    }

    public function test_missing_credentials_give_clear_error(): void
    {
        config(['vois.voice.api_key' => null]);
        app()->forgetInstance(VoiceClient::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('VOIS_API_KEY');
        app(VoiceClient::class);
    }

    public function test_webhook_middleware_accepts_valid_and_rejects_invalid(): void
    {
        $body = json_encode(['id' => 'i', 'event' => 'call.ended', 'data' => [], 'fired_at' => now()->toIso8601String()]);
        $sig = 'sha256=' . hash_hmac('sha256', $body, 'whsecret');

        $this->call('POST', '/vois/webhook', [], [], [], ['HTTP_X_VOIS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk()->assertJson(['got' => 'call.ended']);

        $this->call('POST', '/vois/webhook', [], [], [], ['HTTP_X_VOIS_SIGNATURE' => 'sha256=00', 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(401);
        $this->call('POST', '/vois/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(401);
    }
}
