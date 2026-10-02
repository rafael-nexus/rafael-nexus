<?php
declare(strict_types=1);

function paragraphs(string $text): string
{
    $out = '';
    foreach (preg_split('/\n{2,}/', str_replace("\r\n", "\n", trim($text))) as $p) {
        if ($p !== '') $out .= '<p>' . nl2br(e($p), false) . '</p>';
    }
    return $out;
}

function render(string $title, string $body, int $status = 200, string $flash = ''): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $e = 'e'; // lets the heredoc below call e(): {$e($value)}
    $site = settings();
    $admin = is_admin();
    $full = $title !== '' ? "$title · {$site['site_name']}" : $site['site_name'];
    $year = date('Y');
    $nav = $admin ? '<a href="' . e(url('/admin')) . '">Dashboard</a><a class="btn btn-small" href="' . e(url('/admin/clips/new')) . '">Upload clip</a>' : '';
    $sellerLink = $admin ? '' : '<a href="' . e(url('/admin')) . '">Seller login</a>';
    $flashHtml = $flash !== '' ? '<div class="flash">' . e($flash) . '</div>' : '';
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$e($full)}</title>
<meta name="description" content="{$e($site['tagline'])}">
<link rel="stylesheet" href="{$e(asset('styles.css'))}">
<link rel="icon" href="{$e(asset('favicon.svg'))}" type="image/svg+xml">
</head>
<body>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="{$e(url('/'))}"><span class="reel" aria-hidden="true"></span>{$e($site['site_name'])}</a>
    <nav>
      <a href="{$e(url('/'))}">Catalogue</a>
      <a href="{$e(url('/licence'))}">Licence</a>
      $nav
    </nav>
  </div>
  <div class="sprockets" aria-hidden="true"></div>
</header>
<main class="wrap">
$flashHtml
$body
</main>
<footer class="site-footer">
  <div class="sprockets" aria-hidden="true"></div>
  <div class="wrap footer-inner">
    <span>© $year {$e($site['site_name'])}</span>
    <nav><a href="{$e(url('/licence'))}">Licence terms</a><a href="{$e(url('/imprint'))}">Imprint</a><a href="mailto:{$e($site['contact_email'])}">Contact</a>$sellerLink</nav>
  </div>
</footer>
<script src="{$e(asset('upload.js'))}"></script>
</body>
</html>
HTML;
    exit;
}

function media_url(string $kind, ?string $file): string
{
    return url("/media/$kind/" . rawurlencode((string)$file));
}

function clip_card(array $c): string
{
    $meta = implode(' · ', array_map('e', array_filter([$c['year'], $c['location'], $c['source_format']], fn($v) => $v !== null && $v !== '')));
    $frame = $c['thumb_file']
        ? '<img src="' . e(media_url('thumbs', $c['thumb_file'])) . '" alt="" loading="lazy">'
        : '<div class="no-thumb">No preview</div>';
    $badge = $c['duration_sec'] ? '<span class="badge">' . duration_label($c['duration_sec']) . '</span>' : '';
    return '<a class="card" href="' . e(url('/clips/' . $c['slug'])) . '"><div class="frame">' . $frame . $badge . '</div>'
        . '<div class="card-body"><h3>' . e($c['title']) . '</h3><p class="meta">' . $meta . '</p>'
        . '<p class="price">' . e(money((int)$c['price_cents'])) . '</p></div></a>';
}

function tag_links(array $tags, string $active = ''): string
{
    if (!$tags) return '';
    $out = '';
    foreach ($tags as $t) {
        $out .= '<a class="tag' . ($t === $active ? ' active' : '') . '" href="' . e(url('/', ['tag' => $t])) . '">' . e($t) . '</a>';
    }
    return '<div class="tags">' . $out . '</div>';
}

function view_catalogue(array $clips, array $decades, array $tags, array $query): string
{
    $site = settings();
    $filtered = $query['q'] !== '' || $query['decade'] !== '' || $query['tag'] !== '';
    $opts = '<option value="">Any decade</option>';
    foreach ($decades as $d) {
        $opts .= '<option value="' . e($d) . '"' . ((string)$d === $query['decade'] ? ' selected' : '') . '>' . e($d) . 's</option>';
    }
    $heading = $filtered ? count($clips) . ' result' . (count($clips) === 1 ? '' : 's') : 'Latest additions';
    $grid = $clips
        ? '<div class="grid">' . implode('', array_map('clip_card', $clips)) . '</div>'
        : '<p class="empty">' . ($filtered ? 'No clips match your search.' : 'The archive is empty — sign in and upload your first clip.') . '</p>';
    $action = e(url('/'));
    return '<section class="hero"><h1>' . e($site['site_name']) . '</h1><p>' . e($site['tagline']) . '</p>'
        . '<form class="search" method="get" action="' . $action . '">'
        . '<input type="search" name="q" value="' . e($query['q']) . '" placeholder="Search footage — places, events, subjects…" aria-label="Search">'
        . '<select name="decade" aria-label="Decade">' . $opts . '</select><button class="btn" type="submit">Search</button></form>'
        . tag_links($tags, $query['tag']) . '</section>'
        . '<section><div class="section-head"><h2>' . e($heading) . '</h2>' . ($filtered ? '<a href="' . $action . '">Clear filters</a>' : '') . '</div>'
        . $grid . '</section>';
}

function view_clip(array $c, bool $canBuy, bool $demo, bool $cancelled, string $error = ''): string
{
    $rows = [
        'Year' => $c['year'], 'Location' => $c['location'], 'Original format' => $c['source_format'], 'Colour' => $c['color'],
        'Sound' => $c['sound'], 'Duration' => duration_label($c['duration_sec']), 'Master resolution' => $c['resolution'],
        'Master file' => $c['master_name'] . ' (' . bytes_label($c['master_size']) . ')',
    ];
    $specs = '';
    foreach ($rows as $k => $v) {
        if ($v !== null && $v !== '') $specs .= '<dt>' . e($k) . '</dt><dd>' . e($v) . '</dd>';
    }
    $poster = $c['thumb_file'] ? ' poster="' . e(media_url('thumbs', $c['thumb_file'])) . '"' : '';
    if ($c['preview_file']) {
        $player = '<video controls playsinline preload="metadata" controlslist="nodownload"' . $poster . '><source src="'
            . e(media_url('previews', $c['preview_file'])) . '"></video><p class="note">Low-resolution preview. The full-quality master is delivered after purchase.</p>';
    } elseif ($c['thumb_file']) {
        $player = '<img src="' . e(media_url('thumbs', $c['thumb_file'])) . '" alt="' . e($c['title']) . '">';
    } else {
        $player = '<div class="no-thumb tall">No preview available</div>';
    }
    $buy = $canBuy
        ? '<form method="post" action="' . e(url('/clips/' . $c['slug'] . '/buy')) . '">'
          . '<label>Email for your receipt and download link <input type="email" name="email" required autocomplete="email" placeholder="you@studio.com"></label>'
          . '<label class="check"><input type="checkbox" name="agree" value="1" required> I accept the <a href="' . e(url('/licence')) . '" target="_blank">licence terms</a></label>'
          . '<button class="btn btn-wide" type="submit">Buy &amp; download</button></form>'
          . ($demo ? '<p class="warn">Demo mode: Stripe is not set up yet, so no payment is taken. Add your Stripe key in Site settings before going live.</p>' : '')
        : '<p class="warn">Purchases are temporarily unavailable.</p>';
    return '<article class="clip"><div class="player">' . $player . '</div><div class="details">'
        . '<h1>' . e($c['title']) . '</h1>' . tag_links(split_tags($c['tags']))
        . ($c['description'] !== '' ? '<div class="prose">' . paragraphs($c['description']) . '</div>' : '')
        . '<dl class="specs">' . $specs . '</dl>'
        . '<div class="buy"><p class="price big">' . e(money((int)$c['price_cents'])) . '</p>'
        . '<p class="small">Single-production licence · <a href="' . e(url('/licence')) . '">terms</a></p>'
        . ($cancelled ? '<p class="warn">Checkout was cancelled — you have not been charged.</p>' : '')
        . ($error !== '' ? '<p class="warn">' . e($error) . '</p>' : '')
        . $buy . '</div></div></article>';
}

function view_order(array $order, array $clip): string
{
    if ($order['status'] !== 'paid') {
        return '<section class="panel narrow"><h1>Waiting for payment…</h1><p>We haven\'t received confirmation of your payment for <strong>'
            . e($clip['title']) . '</strong> yet. This page refreshes automatically.</p><meta http-equiv="refresh" content="5"></section>';
    }
    $st = download_state($order);
    $link = ($st['expired'] || $st['left'] <= 0)
        ? '<p class="warn">This download link has expired. Please contact us quoting order <code>' . e($order['id']) . '</code>.</p>'
        : '<a class="btn btn-wide" href="' . e(url('/download/' . $order['download_token'])) . '">Download master — '
          . e($clip['master_name']) . ' (' . bytes_label($clip['master_size']) . ')</a>'
          . '<p class="small">Bookmark this page. The link works ' . $st['left'] . ' more time' . ($st['left'] === 1 ? '' : 's')
          . ' and expires ' . (int)setting('download_ttl_hours') . ' hours after purchase.</p>';
    return '<section class="panel narrow"><h1>Thank you!</h1><p>Your licence for <strong>' . e($clip['title']) . '</strong> is confirmed ('
        . e(money((int)$order['amount_cents'], $order['currency'])) . ').</p>' . $link
        . '<p class="small">Order reference: <code>' . e($order['id']) . '</code></p></section>';
}

function view_text(string $heading, string $text): string
{
    return '<section class="panel narrow"><h1>' . e($heading) . '</h1><div class="prose">' . paragraphs($text) . '</div></section>';
}

function view_login(string $error, string $next, bool $setup = false): string
{
    $insecure = is_https() ? '' : '<p class="note">This connection is not encrypted (HTTP). Enable SSL/HTTPS before using the site for real.</p>';
    $err = $error !== '' ? '<p class="warn">' . e($error) . '</p>' : '';
    if ($setup) {
        return '<section class="panel narrow"><h1>Welcome — set your password</h1>'
            . '<p>This is the first visit to the seller dashboard. Choose the password you will use to sign in. Do this right away, before anyone else finds the site.</p>'
            . $insecure . $err
            . '<form method="post" action="' . e(url('/admin/setup')) . '" class="form">'
            . '<label>New password (at least 10 characters) <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
            . '<label>Repeat password <input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>'
            . '<button class="btn" type="submit">Save password</button></form></section>';
    }
    return '<section class="panel narrow"><h1>Seller login</h1>' . $insecure . $err
        . '<form method="post" action="' . e(url('/admin/login')) . '" class="form"><input type="hidden" name="next" value="' . e($next) . '">'
        . '<label>Password <input type="password" name="password" required autofocus autocomplete="current-password"></label>'
        . '<button class="btn" type="submit">Sign in</button></form></section>';
}

function view_dashboard(array $clips, array $stats, array $orders, array $warnings): string
{
    $warn = '';
    foreach ($warnings as $w) $warn .= '<p class="warn">' . $w . '</p>';
    $rows = '';
    foreach ($clips as $c) {
        $rows .= '<tr><td>' . ($c['thumb_file'] ? '<img class="mini" src="' . e(media_url('thumbs', $c['thumb_file'])) . '" alt="">' : '') . '</td>'
            . '<td><a href="' . e(url('/clips/' . $c['slug'])) . '">' . e($c['title']) . '</a></td><td>' . e($c['year']) . '</td>'
            . '<td>' . e(money((int)$c['price_cents'])) . '</td><td><span class="status ' . e($c['status']) . '">' . e($c['status']) . '</span></td>'
            . '<td>' . e(substr($c['created_at'], 0, 10)) . '</td><td><a href="' . e(url('/admin/clips/' . $c['id'] . '/edit')) . '">Edit</a></td></tr>';
    }
    $clipTable = $clips
        ? '<div class="table-wrap"><table><thead><tr><th></th><th>Title</th><th>Year</th><th>Price</th><th>Status</th><th>Added</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        : '<p class="empty">No clips yet. <a href="' . e(url('/admin/clips/new')) . '">Upload your first clip</a>.</p>';
    $orows = '';
    foreach ($orders as $o) {
        $orows .= '<tr><td>' . e(substr($o['created_at'], 0, 16)) . '</td><td><a href="' . e(url('/clips/' . $o['clip_slug'])) . '">' . e($o['clip_title']) . '</a></td>'
            . '<td>' . e($o['email']) . '</td><td>' . e(money((int)$o['amount_cents'], $o['currency'])) . '</td>'
            . '<td><span class="status ' . e($o['status']) . '">' . e($o['status']) . '</span></td><td>' . (int)$o['downloads'] . '</td></tr>';
    }
    $orderTable = $orders
        ? '<div class="table-wrap"><table><thead><tr><th>Date</th><th>Clip</th><th>Buyer</th><th>Amount</th><th>Status</th><th>Downloads</th></tr></thead><tbody>' . $orows . '</tbody></table></div>'
        : '<p class="empty">No orders yet.</p>';
    return '<div class="section-head"><h1>Dashboard</h1><div class="actions">'
        . '<a class="btn btn-ghost" href="' . e(url('/admin/settings')) . '">Site settings</a>'
        . '<form method="post" action="' . e(url('/admin/logout')) . '">' . csrf_field() . '<button class="btn btn-ghost" type="submit">Log out</button></form>'
        . '<a class="btn" href="' . e(url('/admin/clips/new')) . '">Upload clip</a></div></div>'
        . '<div class="stats"><div><span>' . count($clips) . '</span>clips</div><div><span>' . (int)$stats['paid_count'] . '</span>sales</div>'
        . '<div><span>' . e(money((int)$stats['revenue_cents'])) . '</span>revenue</div></div>'
        . $warn . '<h2>Clips</h2>' . $clipTable . '<h2>Recent orders</h2>' . $orderTable;
}

function view_clip_form(array $clip, string $error = ''): string
{
    $editing = !empty($clip['id']);
    $v = fn($k) => e($clip[$k] ?? '');
    $sel = function (string $name, array $options) use ($clip) {
        $o = '';
        foreach ($options as $opt) $o .= '<option' . (($clip[$name] ?? '') === $opt ? ' selected' : '') . '>' . e($opt) . '</option>';
        return "<select name=\"$name\">$o</select>";
    };
    $inbox = inbox_files();
    $inboxOpts = '<option value="">— upload a file from this device instead —</option>';
    foreach ($inbox as $name => $size) $inboxOpts .= '<option value="' . e($name) . '">' . e($name) . ' (' . bytes_label($size) . ')</option>';
    $limit = bytes_label(max_upload_bytes());
    $price = isset($clip['price_cents']) && $clip['price_cents'] !== null ? number_format($clip['price_cents'] / 100, 2, '.', '') : '';
    $action = $editing ? url('/admin/clips/' . $clip['id']) : url('/admin/clips');

    $html = '<div class="section-head"><h1>' . ($editing ? 'Edit clip' : 'Upload a clip') . '</h1><a href="' . e(url('/admin')) . '">← Dashboard</a></div>'
        . ($error !== '' ? '<p class="warn">' . e($error) . '</p>' : '')
        . '<form class="form panel" method="post" enctype="multipart/form-data" action="' . e($action) . '" id="clip-form" data-max-bytes="' . max_upload_bytes() . '" data-max-label="' . e($limit) . '">' . csrf_field()
        . '<fieldset><legend>Files</legend>'
        . '<label>Master file ' . ($editing ? '(leave empty to keep: ' . e($clip['master_name']) . ')' : '<em>required</em>')
        . '<input type="file" name="master"><span class="hint">The full-quality file buyers download. Never shown publicly. Max ' . e($limit) . ' per upload on this server.</span></label>'
        . '<div class="auto-preview"><label class="check"><input type="checkbox" checked> Create thumbnail and a 15-second watermarked preview from the master automatically</label>'
        . '<p class="auto-status hint" aria-live="polite"></p><video hidden muted playsinline></video><img hidden alt="Thumbnail preview"></div>'
        . '<label>…or pick a large master you uploaded by FTP <select name="inbox_file">' . $inboxOpts . '</select>'
        . '<span class="hint">For files over ' . e($limit) . ': upload them with your FTP app into the <code>data/inbox</code> folder, then reload this page.</span></label>'
        . '<label>Preview clip (optional) <input type="file" name="preview" accept="video/mp4,video/webm,.mp4,.m4v,.webm">'
        . '<span class="hint">Leave empty to use the automatic preview above, or upload your own short, low-resolution MP4.</span></label>'
        . '<label>Thumbnail (optional) <input type="file" name="thumbnail" accept="image/jpeg,image/png,image/webp">'
        . '<span class="hint">Leave empty to use the automatic one, or upload your own JPEG, PNG or WebP.</span></label>'
        . ($editing && !empty($clip['preview_file']) ? '<label class="check"><input type="checkbox" name="remove_preview" value="1"> Remove current preview clip</label>' : '')
        . '</fieldset><fieldset><legend>Details</legend>'
        . '<label>Title <input name="title" required maxlength="140" value="' . $v('title') . '"></label>'
        . '<label>Description <textarea name="description" rows="5">' . $v('description') . '</textarea></label>'
        . '<div class="row"><label>Year <input name="year" type="number" min="1850" max="2100" value="' . $v('year') . '"></label>'
        . '<label>Location <input name="location" value="' . $v('location') . '" placeholder="Berlin, Germany"></label></div>'
        . '<label>Tags <input name="tags" value="' . $v('tags') . '" placeholder="street scene, trams, 1920s"><span class="hint">Comma-separated.</span></label>'
        . '<div class="row"><label>Original format <input name="source_format" value="' . $v('source_format') . '" placeholder="16mm, 35mm, Super 8…"></label>'
        . '<label>Colour ' . $sel('color', ['', 'Black & white', 'Colour', 'Tinted']) . '</label>'
        . '<label>Sound ' . $sel('sound', ['', 'Silent', 'Sound']) . '</label></div>'
        . '<div class="row"><label>Duration (seconds) <input name="duration_sec" type="number" step="0.1" min="0" value="' . $v('duration_sec') . '"></label>'
        . '<label>Master resolution <input name="resolution" value="' . $v('resolution') . '" placeholder="3840×2160"></label></div>'
        . '</fieldset><fieldset><legend>Sale</legend><div class="row">'
        . '<label>Price (' . e(strtoupper(setting('currency'))) . ') <input name="price" type="number" step="0.01" min="0.50" required value="' . e($price) . '"></label>'
        . '<label>Status <select name="status"><option value="published"' . (($clip['status'] ?? '') !== 'draft' ? ' selected' : '') . '>Published — visible in catalogue</option>'
        . '<option value="draft"' . (($clip['status'] ?? '') === 'draft' ? ' selected' : '') . '>Draft — hidden</option></select></label>'
        . '</div></fieldset>'
        . '<div class="upload-progress" hidden><div class="bar"><span></span></div><p>Uploading… <b>0%</b></p></div>'
        . '<button class="btn btn-wide" type="submit">' . ($editing ? 'Save changes' : 'Upload &amp; publish') . '</button></form>';
    if ($editing) {
        $html .= '<form method="post" action="' . e(url('/admin/clips/' . $clip['id'] . '/delete')) . '" class="danger-zone" data-confirm="Delete this clip permanently?">'
            . csrf_field() . '<button class="btn btn-danger" type="submit">Delete clip</button>'
            . '<span class="hint">Clips that have been sold can\'t be deleted — set them to Draft instead.</span></form>';
    }
    return $html;
}

function view_settings(bool $saved, string $error = ''): string
{
    $s = settings();
    $f = function (string $name, string $label, int $rows = 0, string $hint = '', string $type = 'text') use ($s) {
        $h = $hint !== '' ? '<span class="hint">' . $hint . '</span>' : '';
        return $rows
            ? "<label>$label<textarea name=\"$name\" rows=\"$rows\">" . e($s[$name]) . "</textarea>$h</label>"
            : "<label>$label<input type=\"$type\" name=\"$name\" value=\"" . e($s[$name]) . "\" autocomplete=\"off\">$h</label>";
    };
    $webhook = e(site_origin() . url('/webhooks/stripe'));
    return '<div class="section-head"><h1>Site settings</h1><a href="' . e(url('/admin')) . '">← Dashboard</a></div>'
        . ($saved ? '<div class="flash">Settings saved.</div>' : '') . ($error !== '' ? '<p class="warn">' . e($error) . '</p>' : '')
        . '<form class="form panel" method="post" action="' . e(url('/admin/settings')) . '">' . csrf_field()
        . '<fieldset><legend>Site</legend>' . $f('site_name', 'Site name') . $f('tagline', 'Tagline') . $f('contact_email', 'Contact email', 0, '', 'email')
        . $f('license_text', 'Licence terms (shown to buyers)', 8) . $f('imprint_text', 'Imprint / legal notice (Impressum)', 8) . '</fieldset>'
        . '<fieldset><legend>Payments (Stripe)</legend>'
        . $f('stripe_secret_key', 'Stripe secret key', 0, 'Starts with <code>sk_live_</code> (or <code>sk_test_</code> for testing). Find it at dashboard.stripe.com → Developers → API keys. Leave empty for demo mode.', 'password')
        . $f('stripe_webhook_secret', 'Stripe webhook signing secret', 0, 'Starts with <code>whsec_</code>. In Stripe, add a webhook endpoint <code>' . $webhook . '</code> for the event <code>checkout.session.completed</code>.', 'password')
        . '<div class="row">'
        . '<label>Currency <select name="currency">' . implode('', array_map(fn($c) => '<option value="' . $c . '"' . ($s['currency'] === $c ? ' selected' : '') . '>' . strtoupper($c) . '</option>', ['eur', 'usd', 'gbp', 'chf'])) . '</select></label>'
        . $f('download_ttl_hours', 'Download link valid (hours)', 0, '', 'number') . $f('max_downloads', 'Downloads per purchase', 0, '', 'number')
        . '</div></fieldset>'
        . '<fieldset><legend>Change password</legend><label>New password <input type="password" name="new_password" minlength="10" autocomplete="new-password">'
        . '<span class="hint">Leave empty to keep your current password.</span></label></fieldset>'
        . '<button class="btn" type="submit">Save</button></form>';
}
