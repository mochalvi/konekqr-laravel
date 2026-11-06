<?php

namespace KonekQR\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use KonekQR\Laravel\Client;

/**
 * @method static array createPayment(array $params, ?string $idempotencyKey = null)
 * @method static array getPayment(string $id)
 * @method static array listPayments(array $query = [])
 * @method static array cancelPayment(string $id)
 * @method static array simulatePayment(string $id)
 * @method static array listQris()
 * @method static array listDevices()
 * @method static array events(?string $after = null, array $types = [], int $wait = 0)
 *
 * @see Client
 */
class KonekQR extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
