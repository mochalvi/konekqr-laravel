<?php

namespace KonekQR\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use KonekQR\Laravel\Webhook;
use KonekQR\Laravel\WebhookException;
use RuntimeException;

class WebhookTest extends TestCase
{
    public function test_accepts_the_reference_vector(): void
    {
        $now = (int) self::TIMESTAMP;
        $this->assertSame(['test' => 2432232314], Webhook::verify(self::SECRET, self::MSG_ID, self::TIMESTAMP, self::SIGNATURE, self::BODY, 300, $now));
        // Two signatures after a rotation, and several secrets on our side.
        $this->assertSame(['test' => 2432232314], Webhook::verify(self::SECRET, self::MSG_ID, self::TIMESTAMP, 'v1,AAAA '.self::SIGNATURE, self::BODY, 300, $now));
        $this->assertSame(['test' => 2432232314], Webhook::verify(['whsec_b3RoZXI=', self::SECRET], self::MSG_ID, self::TIMESTAMP, self::SIGNATURE, self::BODY, 300, $now + 300));
    }

    public function test_refuses_tampering_and_stale_timestamps(): void
    {
        $now = (int) self::TIMESTAMP;
        $cases = [
            [self::SECRET, self::MSG_ID, self::TIMESTAMP, self::SIGNATURE, self::BODY.' ', $now],
            [self::SECRET, self::MSG_ID, self::TIMESTAMP, self::SIGNATURE, self::BODY, $now + 301],
            [self::SECRET, self::MSG_ID, self::TIMESTAMP, self::SIGNATURE, self::BODY, $now - 301],
            [self::SECRET, 'msg_other', self::TIMESTAMP, self::SIGNATURE, self::BODY, $now],
            [self::SECRET, self::MSG_ID, self::TIMESTAMP, 'v2,'.substr(self::SIGNATURE, 3), self::BODY, $now],
            [self::SECRET, '', self::TIMESTAMP, self::SIGNATURE, self::BODY, $now],
            ['whsec_', self::MSG_ID, self::TIMESTAMP, self::SIGNATURE, self::BODY, $now],
        ];
        foreach ($cases as $i => [$secret, $id, $ts, $sig, $body, $at]) {
            try {
                Webhook::verify($secret, $id, $ts, $sig, $body, 300, $at);
                $this->fail("case {$i} accepted");
            } catch (WebhookException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_middleware_lets_only_signed_webhooks_through(): void
    {
        Route::post('/webhooks/konekqr', fn (Request $request) => response()->json($request->attributes->get('konekqr_event')))
            ->middleware('konekqr.webhook');
        $this->app['config']->set('konekqr.webhook_tolerance', PHP_INT_MAX); // the vector is from 2021
        $send = fn (string $body, string $signature) => $this->call('POST', '/webhooks/konekqr', [], [], [], [
            'HTTP_WEBHOOK_ID' => self::MSG_ID,
            'HTTP_WEBHOOK_TIMESTAMP' => self::TIMESTAMP,
            'HTTP_WEBHOOK_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $send(self::BODY, self::SIGNATURE)->assertOk()->assertExactJson(['test' => 2432232314]);
        $send(self::BODY.' ', self::SIGNATURE)->assertStatus(400)->assertJson(['error' => 'invalid_webhook_signature']);

        // Rotation: both secrets in the env, comma-separated.
        $this->app['config']->set('konekqr.webhook_secret', 'whsec_b3RoZXI=, '.self::SECRET);
        $send(self::BODY, self::SIGNATURE)->assertOk();

        $this->app['config']->set('konekqr.webhook_tolerance', 300);
        $send(self::BODY, self::SIGNATURE)->assertStatus(400);
    }

    public function test_middleware_without_secret_is_a_server_error(): void
    {
        Route::post('/webhooks/konekqr', fn () => 'ok')->middleware('konekqr.webhook');
        $this->app['config']->set('konekqr.webhook_secret', null);
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);
        $this->post('/webhooks/konekqr', [], ['webhook-id' => self::MSG_ID]);
    }
}
