<?php

namespace KonekQR\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use KonekQR\Laravel\Webhook;
use KonekQR\Laravel\WebhookException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a Konek QR webhook through only when its Standard Webhooks signature
 * matches konekqr.webhook_secret. The verified event is the request attribute
 * "konekqr_event". Exclude the route from CSRF protection.
 */
class VerifyKonekQrWebhook
{
    public function __construct(private Repository $config)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secrets = array_values(array_filter(array_map('trim', explode(',', (string) $this->config->get('konekqr.webhook_secret')))));
        if ($secrets === []) {
            throw new RuntimeException('Set KONEKQR_WEBHOOK_SECRET to the whsec_… secret of your Konek QR webhook endpoint.');
        }
        try {
            $event = Webhook::verify(
                $secrets,
                (string) $request->header('webhook-id', ''),
                (string) $request->header('webhook-timestamp', ''),
                (string) $request->header('webhook-signature', ''),
                $request->getContent(),
                (int) $this->config->get('konekqr.webhook_tolerance', 300),
            );
        } catch (WebhookException $e) {
            // 400, not 401: Konek QR's Webhook Doctor reads 401 as "the route wants a login".
            return new JsonResponse(['error' => 'invalid_webhook_signature', 'message' => $e->getMessage()], 400);
        }
        $request->attributes->set('konekqr_event', $event);

        return $next($request);
    }
}
