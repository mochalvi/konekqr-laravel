<?php

namespace KonekQR\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use KonekQR\Laravel\Client;
use KonekQR\Laravel\Facades\KonekQR;
use KonekQR\Laravel\KonekQRException;

class ClientTest extends TestCase
{
    private function invoice(array $over = []): array
    {
        return $over + [
            'id' => '01972d1c-4b8e-7c3a-9d2f-1a2b3c4d5e6f', 'kind' => 'invoice', 'livemode' => false, 'status' => 'pending',
            'amount' => 25193, 'baseAmount' => 25000, 'uniqueCode' => 193, 'referenceId' => 'INV-1', 'metadata' => [],
        ];
    }

    public function test_create_payment_sends_key_idempotency_and_json(): void
    {
        Http::fake(['api.konekqr.web.id/v1/payments' => Http::response(['payment' => $this->invoice()], 201)]);

        $payment = KonekQR::createPayment(['amount' => 25000, 'referenceId' => 'INV-1', 'metadata' => []], idempotencyKey: 'order-1');
        KonekQR::createPayment(['amount' => 25000, 'referenceId' => 'INV-2']);
        KonekQR::createPayment(['amount' => 25000]);

        $this->assertSame(25193, $payment['amount']);
        $sent = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(3, $sent);
        /** @var Request $first */
        $first = $sent[0];
        $this->assertSame('POST', $first->method());
        $this->assertSame('https://api.konekqr.web.id/v1/payments', $first->url());
        $this->assertSame('Bearer '.self::KEY, $first->header('Authorization')[0]);
        $this->assertSame('order-1', $first->header('Idempotency-Key')[0]);
        $this->assertSame('id', $first->header('Accept-Language')[0]);
        $this->assertSame('{"amount":25000,"referenceId":"INV-1","metadata":{}}', $first->body());
        $this->assertSame('INV-2', $sent[1]->header('Idempotency-Key')[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $sent[2]->header('Idempotency-Key')[0]);
    }

    public function test_a_replayed_invoice_comes_back_as_it_is_now(): void
    {
        $replayed = ['Idempotency-Replayed' => 'true'];
        Http::fake(['*' => Http::sequence()
            ->push(['payment' => $this->invoice()], 201, $replayed)
            ->push(['payment' => $this->invoice(['status' => 'expired'])])
            ->push(['payment' => $this->invoice()], 201, $replayed)
            ->push(['code' => 'insufficient_scope'], 403),
        ]);

        $this->assertSame('expired', KonekQR::createPayment(['amount' => 25000, 'referenceId' => 'INV-1'])['status']);
        $this->assertSame('GET https://api.konekqr.web.id/v1/payments/'.$this->invoice()['id'],
            Http::recorded()[1][0]->method().' '.Http::recorded()[1][0]->url());
        // A key that may only write gets the invoice as it was created.
        $this->assertSame('pending', KonekQR::createPayment(['amount' => 25000, 'referenceId' => 'INV-1'])['status']);
    }

    public function test_reads_and_changes_payments(): void
    {
        $id = '01972d1c-4b8e-7c3a-9d2f-1a2b3c4d5e6f';
        Http::fake([
            "*/v1/payments/{$id}/cancel" => Http::response(['payment' => $this->invoice(['status' => 'cancelled'])]),
            "*/v1/payments/{$id}/simulate" => Http::response(['payment' => $this->invoice(['status' => 'paid', 'paidVia' => 'simulation'])]),
            "*/v1/payments/{$id}" => Http::response(['payment' => $this->invoice()]),
            '*/v1/payments*' => Http::response(['payments' => [$this->invoice()]]),
            '*/v1/qris' => Http::response(['qris' => []]),
            '*/v1/devices' => Http::response(['devices' => [['id' => 'd', 'name' => 'HP Kasir', 'online' => true, 'lastSeenAt' => null]]]),
            '*/v1/events*' => Http::response(['events' => [], 'next' => 'evt_x']),
        ]);

        $this->assertSame($id, KonekQR::getPayment($id)['id']);
        $this->assertCount(1, KonekQR::listPayments(['status' => 'paid', 'limit' => 10]));
        $this->assertSame('cancelled', KonekQR::cancelPayment($id)['status']);
        $this->assertSame('simulation', KonekQR::simulatePayment($id)['paidVia']);
        $this->assertSame([], KonekQR::listQris());
        $this->assertSame('HP Kasir', KonekQR::listDevices()[0]['name']);
        $this->assertSame(['events' => [], 'next' => 'evt_x'], KonekQR::events(after: 'evt_a', types: ['payment.paid', 'payment.expired'], wait: 30));

        $calls = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.urldecode($pair[0]->url()))->all();
        $this->assertSame([
            "GET https://api.konekqr.web.id/v1/payments/{$id}",
            'GET https://api.konekqr.web.id/v1/payments?status=paid&limit=10',
            "POST https://api.konekqr.web.id/v1/payments/{$id}/cancel",
            "POST https://api.konekqr.web.id/v1/payments/{$id}/simulate",
            'GET https://api.konekqr.web.id/v1/qris',
            'GET https://api.konekqr.web.id/v1/devices',
            'GET https://api.konekqr.web.id/v1/events?after=evt_a&types=payment.paid,payment.expired&wait=30',
        ], $calls);
        $this->assertSame('', Http::recorded()[2][0]->body());
    }

    public function test_error_answers_become_exceptions(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['type' => 'https://konekqr.web.id/errors/reference_taken', 'title' => 'referenceId already used', 'status' => 409, 'code' => 'reference_taken', 'detail' => 'sudah dipakai'], 409)
            ->push(['code' => 'validation_failed', 'detail' => 'x', 'errors' => [['field' => 'amount', 'code' => 'amount_out_of_range', 'message' => 'm']]], 422)
            ->push(['code' => 'rate_limited', 'detail' => 'tunggu'], 429, ['Retry-After' => '7'])
            ->push('<html>502 Bad Gateway</html>', 502),
        ]);

        $errors = [];
        foreach (range(1, 4) as $_) {
            try {
                KonekQR::createPayment(['amount' => 25000], 'k');
                $this->fail('no exception');
            } catch (KonekQRException $e) {
                $errors[] = $e;
            }
        }
        $this->assertSame([409, 'reference_taken', 'sudah dipakai'], [$errors[0]->status, $errors[0]->errorCode, $errors[0]->detail]);
        $this->assertSame('amount_out_of_range', $errors[1]->errors[0]['code']);
        $this->assertSame(7, $errors[2]->retryAfter);
        $this->assertSame([502, 'http_error'], [$errors[3]->status, $errors[3]->errorCode]);
    }

    public function test_config_and_key_check(): void
    {
        $this->app['config']->set('konekqr.base_url', 'http://127.0.0.1:8080/');
        $this->app['config']->set('konekqr.language', 'en');
        $this->app->forgetInstance(Client::class);
        Http::fake(['*' => Http::response(['qris' => []])]);

        KonekQR::clearResolvedInstances();
        KonekQR::listQris();
        $request = Http::recorded()[0][0];
        $this->assertSame('http://127.0.0.1:8080/v1/qris', $request->url());
        $this->assertSame('en', $request->header('Accept-Language')[0]);

        $this->expectException(InvalidArgumentException::class);
        new Client($this->app->make(\Illuminate\Http\Client\Factory::class), 'whsec_not_a_key');
    }
}
