<?php

namespace KonekQR\Laravel\Tests;

use KonekQR\Laravel\KonekQRServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const KEY = 'kq_test_ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // The reference vector of the Standard Webhooks libraries (also in backend/internal/webhook).
    public const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    public const MSG_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';

    public const TIMESTAMP = '1614265330';

    public const BODY = '{"test": 2432232314}';

    public const SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

    protected function getPackageProviders($app): array
    {
        return [KonekQRServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['KonekQR' => \KonekQR\Laravel\Facades\KonekQR::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('konekqr.api_key', self::KEY);
        $app['config']->set('konekqr.webhook_secret', self::SECRET);
    }
}
