<?php

namespace Vois\Laravel;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Vois\Laravel\Middleware\VerifyVoisWebhook;
use Vois\Sms\SmsClient;
use Vois\Voice\VoiceClient;
use Vois\Webhooks\WebhookClient;

final class VoisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/vois.php', 'vois');

        $this->app->singleton(SmsClient::class, function ($app) {
            $c = $app['config']['vois.sms'];
            if (empty($c['username']) && empty($c['password']) && ! empty($c['api_key'])) {
                return SmsClient::withApiKey($c['api_key']);
            }
            if (empty($c['username']) || empty($c['password'])) {
                throw new \InvalidArgumentException('VOIS SMS bilgileri eksik: .env dosyasına VOIS_SMS_USERNAME ve VOIS_SMS_PASSWORD (ya da VOIS_API_KEY) ekleyin.');
            }

            return new SmsClient($c['username'], $c['password'], ['base_uri' => $c['base_uri'] ?? SmsClient::DEFAULT_BASE_URI]);
        });

        $this->app->singleton(VoiceClient::class, function ($app) {
            $c = $app['config']['vois.voice'];
            if (empty($c['api_key'])) {
                throw new \InvalidArgumentException('VOIS Ses API anahtarı eksik: .env dosyasına VOIS_API_KEY ekleyin.');
            }

            return new VoiceClient($c['api_key'], ['base_uri' => $c['base_uri'] ?? VoiceClient::DEFAULT_BASE_URI]);
        });

        $this->app->singleton(WebhookClient::class, function ($app) {
            $key = $app['config']['vois.voice.api_key'] ?: $app['config']['vois.sms.api_key'];
            if (empty($key)) {
                throw new \InvalidArgumentException('VOIS API anahtarı eksik: .env dosyasına VOIS_API_KEY ekleyin.');
            }

            return new WebhookClient($key);
        });

        $this->app->alias(SmsClient::class, 'vois.sms');
        $this->app->alias(VoiceClient::class, 'vois.voice');
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('vois.webhook', VerifyVoisWebhook::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/vois.php' => config_path('vois.php')], 'vois-config');
        }
    }
}
