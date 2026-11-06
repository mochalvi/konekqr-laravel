<?php

namespace KonekQR\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use KonekQR\Laravel\Http\Middleware\VerifyKonekQrWebhook;

class KonekQRServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/konekqr.php', 'konekqr');

        $this->app->singleton(Client::class, function ($app) {
            $config = $app['config'];

            return new Client(
                $app->make(Factory::class),
                (string) $config->get('konekqr.api_key'),
                (string) $config->get('konekqr.base_url', 'https://api.konekqr.web.id'),
                (string) $config->get('konekqr.language', 'id'),
                (int) $config->get('konekqr.timeout', 30),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/konekqr.php' => config_path('konekqr.php')], 'konekqr-config');
        $this->app['router']->aliasMiddleware('konekqr.webhook', VerifyKonekQrWebhook::class);
    }
}
