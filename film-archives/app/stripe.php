<?php
declare(strict_types=1);

// Minimal Stripe Checkout integration over the REST API (no SDK or Composer needed).

function stripe_enabled(): bool
{
    return setting('stripe_secret_key') !== '';
}

function stripe_request(string $method, string $path, array $params = []): array
{
    $url = 'https://api.stripe.com/v1' . $path;
    $body = http_build_query($params);
    $headers = ['Authorization: Bearer ' . setting('stripe_secret_key'), 'Content-Type: application/x-www-form-urlencoded'];

    if (function_exists('curl_init')) {
        $ch = curl_init($method === 'GET' && $body ? "$url?$body" : $url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("Stripe connection failed: $err");
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 30,
            'content' => $method === 'GET' ? '' : $body,
        ]]);
        $raw = @file_get_contents($method === 'GET' && $body ? "$url?$body" : $url, false, $ctx);
        if ($raw === false) throw new RuntimeException('Stripe connection failed');
        preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
        $status = (int)($m[1] ?? 0);
    }
    $data = json_decode($raw, true) ?: [];
    if ($status >= 400) throw new RuntimeException('Stripe error: ' . ($data['error']['message'] ?? "HTTP $status"));
    return $data;
}

function stripe_create_checkout(array $order, array $clip): array
{
    return stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'customer_email' => $order['email'],
        'client_reference_id' => $order['id'],
        'metadata' => ['order_id' => $order['id']],
        // Stripe may enable Managed Payments (merchant of record, extra fee) by default, which then
        // requires product tax codes. This shop handles payments itself, so opt out per session.
        'managed_payments' => ['enabled' => 'false'],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => $order['currency'],
                'unit_amount' => $order['amount_cents'],
                'product_data' => ['name' => $clip['title'], 'description' => 'Footage licence - ' . $clip['master_name']],
            ],
        ]],
        'success_url' => site_origin() . url('/orders/' . $order['id']),
        'cancel_url' => site_origin() . url('/clips/' . $clip['slug'], ['cancelled' => 1]),
    ]);
}

function stripe_get_checkout(string $sessionId): array
{
    return stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
}

// Verifies the Stripe-Signature header (https://docs.stripe.com/webhooks#verify-manually).
function stripe_verify_webhook(string $payload, string $header, int $tolerance = 300): ?array
{
    $secret = setting('stripe_webhook_secret');
    if ($secret === '' || $header === '') return null;
    $t = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $t = $v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if ($t === null || !$sigs || abs(time() - (int)$t) > $tolerance) return null;
    $expected = hash_hmac('sha256', "$t.$payload", $secret);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) return json_decode($payload, true);
    }
    return null;
}
