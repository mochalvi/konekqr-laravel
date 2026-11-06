# konekqr/laravel

Klien API Konek QR untuk Laravel 12 dan 13 (PHP 8.2+; Laravel 13 butuh PHP 8.3+): service provider, facade `KonekQR`, dan middleware verifikasi webhook `VerifyKonekQrWebhook`. Memakai HTTP client bawaan Laravel.

```bash
composer require konekqr/laravel
php artisan vendor:publish --tag=konekqr-config   # opsional
```

`.env`:

```dotenv
KONEKQR_API_KEY=kq_test_...
KONEKQR_WEBHOOK_SECRET=whsec_...
# KONEKQR_LANGUAGE=en          # bahasa teks error, default id
# KONEKQR_TIMEOUT=30
```

## Tagihan

```php
use KonekQR\Laravel\Facades\KonekQR;
use KonekQR\Laravel\KonekQRException;

$payment = KonekQR::createPayment([
    'amount' => 25000,
    'description' => 'Kopi susu 2x',
    'referenceId' => $order->number,
    'expiresInMinutes' => 15,
    'returnUrl' => route('orders.show', $order),
    'metadata' => ['orderId' => $order->id],
], idempotencyKey: "order-{$order->id}");

return redirect()->away($payment['payUrl']); // atau tampilkan QR dari $payment['qrString'] dan total $payment['amount']
```

`Idempotency-Key` diambil dari `$idempotencyKey`, lalu `referenceId`, lalu UUID acak. Pakai id pesananmu supaya mengulang permintaan yang timeout tidak membuat tagihan kedua.

Key yang sama dalam 24 jam memutar ulang tagihan pertama, bukan menjawab `409 reference_taken`, juga kalau key-nya diambil dari `referenceId`. `createPayment` lalu mengembalikan tagihan itu dalam keadaan sekarang: cek `$payment['status']`, karena bisa saja sudah lunas, dibatalkan, atau kedaluwarsa. Untuk tagihan baru setelah yang lama kedaluwarsa, pakai `referenceId` dan key baru.

Metode lain (semuanya mengembalikan array dari JSON API, lihat https://api.konekqr.web.id/openapi.yaml):

```php
KonekQR::getPayment($id);
KonekQR::listPayments(['status' => 'paid', 'limit' => 50]);   // terbaru dulu; halaman berikut: 'before' => id terakhir
KonekQR::cancelPayment($id);
KonekQR::simulatePayment($id);                                 // key uji saja
KonekQR::listQris();
KonekQR::listDevices();
KonekQR::events(after: $cursor, types: ['payment.paid'], wait: 30); // ['events' => [...], 'next' => 'evt_…']
```

Error API menjadi `KonekQRException` dengan `status`, `errorCode` (stabil, untuk dibandingkan), `detail`, `errors` (per field, untuk 422), dan `retryAfter` (429). Gangguan jaringan tetap `Illuminate\Http\Client\ConnectionException`.

```php
try {
    $payment = KonekQR::createPayment(['amount' => 25000, 'requireDetection' => true], "order-{$order->id}");
} catch (KonekQRException $e) {
    if ($e->errorCode !== 'detection_offline') {
        throw $e;
    }
    // HP Listener offline: tawarkan metode bayar lain
}
```

## Webhook

```php
// routes/web.php (atau routes/api.php)
use Illuminate\Http\Request;

Route::post('/webhooks/konekqr', function (Request $request) {
    $event = $request->attributes->get('konekqr_event'); // sudah diverifikasi
    if ($event['type'] === 'payment.paid') {
        ProcessKonekQRPayment::dispatch($event); // dedupe dengan $event['id']
    }

    return response()->noContent();
})->middleware('konekqr.webhook');
```

Route di `routes/web.php` harus dikecualikan dari CSRF, kalau tidak Laravel menjawab 419:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['webhooks/konekqr']);
})
```

Middleware menjawab `400` untuk tanda tangan yang salah atau timestamp lebih dari 5 menit (`KONEKQR_WEBHOOK_TOLERANCE`), dan error server kalau `KONEKQR_WEBHOOK_SECRET` belum diisi. Saat merotasi secret, isi keduanya dipisah koma: `KONEKQR_WEBHOOK_SECRET=whsec_baru,whsec_lama`.

Verifikasi tanpa middleware: `KonekQR\Laravel\Webhook::verify($secret, $id, $timestamp, $signatures, $rawBody)`.

## Pengembangan

```bash
composer install
composer test     # PHPUnit + Orchestra Testbench
```

## Lisensi

MIT
