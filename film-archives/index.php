<?php
declare(strict_types=1);

// Film Archives — storefront for selling archival film clips.
// Upload this folder to your web space; everything runs through this file.

if (PHP_VERSION_ID < 80100) exit('Film Archives needs PHP 8.1 or newer. This server runs PHP ' . PHP_VERSION . '.');
if (!extension_loaded('pdo_sqlite')) exit('Film Archives needs the PHP extension pdo_sqlite. Please ask your host to enable it.');

require __DIR__ . '/app/bootstrap.php';

if (!is_writable(FA_DATA_DIR)) {
    http_response_code(500);
    exit('The folder "data" is not writable. Set its permissions to 755 (or 775) in your FTP app.');
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; frame-ancestors 'none'; "
    . "form-action 'self' https://checkout.stripe.com; base-uri 'none'; object-src 'none'");

set_exception_handler(function (Throwable $e) {
    error_log('Film Archives: ' . $e);
    if (!headers_sent()) {
        render('Error', view_text('Something went wrong', 'Please try again in a moment.'), 500);
    }
    exit;
});

// ---------- Work out the requested path ----------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Taken from REQUEST_URI rather than PATH_INFO, which PHP-FPM setups fill in inconsistently.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (base_dir() !== '' && str_starts_with($path, base_dir() . '/')) $path = substr($path, strlen(base_dir()));
if (str_starts_with($path, '/index.php')) $path = substr($path, strlen('/index.php'));
$path = '/' . trim($path, '/');

function not_found(): void
{
    render('Not found', view_text('Not found', "We couldn't find that page."), 404);
}

function route(string $pattern, string $path, ?array &$m): bool
{
    return (bool)preg_match('#^' . $pattern . '$#', $path, $m);
}

// A POST larger than post_max_size arrives with $_POST and $_FILES empty.
if ($method === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && !str_starts_with($path, '/webhooks/')) {
    $msg = 'The upload is larger than this server allows (' . bytes_label(max_upload_bytes()) . '). '
        . 'Upload big masters by FTP into the data/inbox folder and pick them from the list instead.';
    render('Upload failed', is_admin() ? view_clip_form([], $msg) : view_text('Upload failed', $msg), 413);
}

// ---------- Public storefront ----------

if ($method === 'GET' && $path === '/') {
    $query = [
        'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
        'decade' => (string)($_GET['decade'] ?? ''),
        'tag' => (string)($_GET['tag'] ?? ''),
    ];
    render('', view_catalogue(clips_search($query['q'], $query['decade'], $query['tag']), clip_decades(), clip_tags(), $query));
}

// Public media: only thumbnails and previews. Masters are never served here.
if ($method === 'GET' && route('/media/(thumbs|previews)/([A-Za-z0-9-]+\.[a-z0-9]+)', $path, $m)) {
    $types = $m[1] === 'thumbs' ? THUMB_TYPES : PREVIEW_TYPES;
    $ext = file_ext($m[2]);
    if (!isset($types[$ext])) not_found();
    send_file(data_path($m[1], $m[2]), $types[$ext]);
}

// Purchases are open to everyone once Stripe is set up; before that, only the logged-in seller can test them.
function purchases_open(): bool
{
    return stripe_enabled() || is_admin();
}

function public_clip(string $slug): array
{
    $clip = clip_by_slug($slug);
    if (!$clip || ($clip['status'] !== 'published' && !is_admin())) not_found();
    return $clip;
}

if ($method === 'GET' && route('/clips/([a-z0-9-]+)', $path, $m)) {
    $clip = public_clip($m[1]);
    render($clip['title'], view_clip($clip, purchases_open() && $clip['status'] === 'published', !stripe_enabled(), isset($_GET['cancelled'])));
}

if ($method === 'POST' && route('/clips/([a-z0-9-]+)/buy', $path, $m)) {
    $clip = public_clip($m[1]);
    $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 254);
    if ($clip['status'] !== 'published' || !purchases_open()) redirect(url('/clips/' . $clip['slug']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || ($_POST['agree'] ?? '') !== '1') {
        render($clip['title'], view_clip($clip, true, !stripe_enabled(), false, 'Please enter a valid email address and accept the licence terms.'), 400);
    }
    $order = order_create($clip['id'], $email, (int)$clip['price_cents'], setting('currency'));
    if (!stripe_enabled()) {
        order_mark_paid($order['id']); // demo purchase by the logged-in seller
        redirect(url('/orders/' . $order['id']));
    }
    try {
        $session = stripe_create_checkout($order, $clip);
    } catch (Throwable $e) {
        error_log('Film Archives: ' . $e->getMessage());
        render($clip['title'], view_clip($clip, true, false, false, 'Payment could not be started. Please try again later.'), 502);
    }
    q('UPDATE orders SET stripe_session_id = ? WHERE id = ?', [$session['id'], $order['id']]);
    redirect($session['url']);
}

if ($method === 'GET' && route('/orders/([0-9a-f-]{36})', $path, $m)) {
    $order = order_get($m[1]) ?? null;
    if (!$order) not_found();
    // Confirm with Stripe directly in case the webhook hasn't arrived yet.
    if ($order['status'] !== 'paid' && stripe_enabled() && $order['stripe_session_id']) {
        try {
            $s = stripe_get_checkout($order['stripe_session_id']);
            if (($s['payment_status'] ?? '') === 'paid' && ($s['metadata']['order_id'] ?? '') === $order['id']) {
                order_mark_paid($order['id']);
                $order = order_get($order['id']);
            }
        } catch (Throwable $e) {
            error_log('Film Archives: Stripe lookup failed: ' . $e->getMessage());
        }
    }
    header('Cache-Control: no-store');
    render('Your order', view_order($order, clip_get($order['clip_id'])));
}

if ($method === 'GET' && route('/download/([A-Za-z0-9_-]{20,64})', $path, $m)) {
    $order = order_by_token($m[1]);
    if (!$order || $order['status'] !== 'paid') not_found();
    $st = download_state($order);
    if ($st['expired'] || $st['left'] <= 0) redirect(url('/orders/' . $order['id']), 302);
    $clip = clip_get($order['clip_id']);
    // Count only fresh downloads, not resumed ones (Range requests from download managers or Safari).
    if (empty($_SERVER['HTTP_RANGE']) || preg_match('/bytes=0-/', $_SERVER['HTTP_RANGE'])) {
        q('UPDATE orders SET downloads = downloads + 1 WHERE id = ?', [$order['id']]);
    }
    send_file(data_path('masters', $clip['master_file']), 'application/octet-stream', $clip['master_name'], false);
}

if ($method === 'GET' && $path === '/licence') render('Licence terms', view_text('Licence terms', setting('license_text')));
if ($method === 'GET' && $path === '/imprint') render('Imprint', view_text('Imprint', setting('imprint_text')));

if ($method === 'POST' && $path === '/webhooks/stripe') {
    $event = stripe_verify_webhook((string)file_get_contents('php://input'), (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    if (!$event) { http_response_code(400); exit('invalid signature'); }
    if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
        $s = $event['data']['object'] ?? [];
        if (($s['payment_status'] ?? '') === 'paid' && !empty($s['metadata']['order_id'])) order_mark_paid((string)$s['metadata']['order_id']);
    }
    header('Content-Type: application/json');
    exit('{"received":true}');
}

// ---------- Seller admin ----------

if (str_starts_with($path, '/admin')) require __DIR__ . '/app/admin.php';

not_found();
