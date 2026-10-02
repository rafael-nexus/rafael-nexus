<?php
declare(strict_types=1);

// Seller dashboard routes. Included from index.php for every /admin… path.

header('Cache-Control: no-store');

// First visit: no password yet, so ask the seller to choose one.
if (!admin_password_set()) {
    if ($method === 'POST' && $path === '/admin/setup') {
        $pw = (string)($_POST['password'] ?? '');
        if (mb_strlen($pw) < 10) render('Set password', view_login('Please use at least 10 characters.', '', true), 400);
        if ($pw !== (string)($_POST['password2'] ?? '')) render('Set password', view_login('The two passwords are different.', '', true), 400);
        save_settings(['admin_password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
        admin_login();
        redirect(url('/admin/settings', ['welcome' => 1]));
    }
    render('Set password', view_login('', '', true));
}

if ($path === '/admin/login') {
    if ($method === 'GET') {
        if (is_admin()) redirect(url('/admin'), 302);
        render('Seller login', view_login('', (string)($_GET['next'] ?? '')));
    }
    $next = (string)($_POST['next'] ?? '');
    $next = preg_match('#^/[^/\\\\]#', $next) && str_contains($next, '/admin') ? $next : url('/admin');
    if (!login_allowed()) render('Seller login', view_login('Too many attempts. Try again in 15 minutes.', $next), 429);
    if (!check_password((string)($_POST['password'] ?? ''))) {
        record_login_failure();
        render('Seller login', view_login('Wrong password.', $next), 401);
    }
    admin_login();
    redirect($next);
}

require_admin();

// Every admin form carries a CSRF token.
if ($method === 'POST' && !csrf_check()) {
    render('Session expired', view_text('Session expired', 'Your session expired or the form was sent from another site. Please go back, reload the page and try again.'), 403);
}

if ($method === 'POST' && $path === '/admin/logout') {
    admin_logout();
    redirect(url('/'));
}

// ---------- Dashboard ----------

// Checks that the data folder can't be downloaded directly from the web (it holds masters and the database).
function data_folder_exposed(): bool
{
    // Re-checked at most once an hour; the result is kept in settings as "timestamp|0 or 1".
    [$at, $result] = array_pad(explode('|', setting('data_check')), 2, '0');
    if (time() - (int)$at < 3600) return $result === '1';
    $exposed = false;
    if (FA_DATA_DIR === FA_ROOT . '/data' && function_exists('curl_init')) {
        $ch = curl_init(site_origin() . base_dir() . '/data/film-archives.sqlite');
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 4, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
        curl_exec($ch);
        $exposed = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
    }
    save_settings(['data_check' => time() . '|' . ($exposed ? '1' : '0')]);
    return $exposed;
}

if ($method === 'GET' && $path === '/admin') {
    $warnings = [];
    if (data_folder_exposed()) {
        $warnings[] = '<strong>Security:</strong> the <code>data</code> folder can be opened from the internet, so masters could be downloaded without paying. '
            . 'Make sure the file <code>data/.htaccess</code> was uploaded, or ask your host to block web access to that folder.';
    }
    if (!stripe_enabled()) {
        $warnings[] = 'Stripe is not set up, so buyers can\'t purchase yet (you can test a purchase while logged in). '
            . '<a href="' . e(url('/admin/settings')) . '">Add your Stripe key in Site settings.</a>';
    }
    if (!is_https()) $warnings[] = 'The site is running without HTTPS. Enable SSL with your host before selling.';
    if (strpos(setting('imprint_text'), 'Operator name') !== false) {
        $warnings[] = 'Your imprint still contains the placeholder text. <a href="' . e(url('/admin/settings')) . '">Fill it in under Site settings.</a>';
    }
    if ($inbox = inbox_files()) {
        $warnings[] = count($inbox) . ' file(s) waiting in <code>data/inbox</code>. Pick them in the form when you <a href="' . e(url('/admin/clips/new')) . '">upload a clip</a>.';
    }
    render('Dashboard', view_dashboard(clips_all(), order_stats(), orders_all(), $warnings));
}

// ---------- Settings ----------

if ($path === '/admin/settings') {
    if ($method === 'POST') {
        $values = array_intersect_key($_POST, array_flip(['site_name', 'tagline', 'contact_email', 'license_text', 'imprint_text',
            'stripe_secret_key', 'stripe_webhook_secret', 'currency', 'download_ttl_hours', 'max_downloads']));
        $values['currency'] = in_array($values['currency'] ?? '', ['eur', 'usd', 'gbp', 'chf'], true) ? $values['currency'] : 'eur';
        $values['download_ttl_hours'] = (string)max(1, (int)($values['download_ttl_hours'] ?? 72));
        $values['max_downloads'] = (string)max(1, (int)($values['max_downloads'] ?? 5));
        $key = trim((string)($values['stripe_secret_key'] ?? ''));
        if ($key !== '' && !preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/', $key)) {
            render('Settings', view_settings(false, 'That doesn\'t look like a Stripe secret key (it should start with sk_live_ or sk_test_).'), 400);
        }
        $new = (string)($_POST['new_password'] ?? '');
        if ($new !== '') {
            if (mb_strlen($new) < 10) render('Settings', view_settings(false, 'The new password needs at least 10 characters.'), 400);
            $values['admin_password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        }
        save_settings($values);
        if (isset($values['admin_password_hash'])) admin_login(); // stay logged in on this device
        redirect(url('/admin/settings', ['saved' => 1]));
    }
    $welcome = isset($_GET['welcome']) ? 'Password saved. Now fill in your site details, imprint and (when ready) your Stripe key.' : '';
    render('Settings', view_settings(isset($_GET['saved'])), 200, $welcome);
}

// ---------- Clips ----------

function clip_fields_from_post(): array
{
    $year = filter_var($_POST['year'] ?? '', FILTER_VALIDATE_INT);
    $dur = filter_var($_POST['duration_sec'] ?? '', FILTER_VALIDATE_FLOAT);
    $price = filter_var(str_replace(',', '.', (string)($_POST['price'] ?? '')), FILTER_VALIDATE_FLOAT);
    $str = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    return [
        'title' => $str('title', 140),
        'description' => $str('description', 5000),
        'year' => $year === false ? null : $year,
        'location' => $str('location', 140),
        'tags' => implode(', ', array_slice(split_tags($_POST['tags'] ?? ''), 0, 20)),
        'source_format' => $str('source_format', 60),
        'color' => $str('color', 30),
        'sound' => $str('sound', 30),
        'duration_sec' => $dur === false ? null : $dur,
        'resolution' => $str('resolution', 30),
        'price_cents' => $price === false ? 0 : (int)round($price * 100),
        'status' => ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published',
    ];
}

// Validates the form and moves uploaded files into place. Returns [fields, error].
function save_clip_files(array $fields, ?array $existing): array
{
    if ($fields['title'] === '') return [$fields, 'Please enter a title.'];
    if ($fields['price_cents'] < 50) return [$fields, 'The price must be at least 0.50.'];

    $master = $_FILES['master'] ?? null;
    $preview = $_FILES['preview'] ?? null;
    $thumb = $_FILES['thumbnail'] ?? null;
    foreach ([$master, $preview, $thumb] as $f) {
        if ($err = upload_error($f)) return [$fields, $err];
    }
    $inbox = basename((string)($_POST['inbox_file'] ?? ''));
    $inboxPath = $inbox !== '' ? data_path('inbox', $inbox) : '';
    if ($inbox !== '' && !is_file($inboxPath)) return [$fields, 'The selected FTP file no longer exists in data/inbox.'];
    // 32-bit PHP (like the production server) can't read or stream files of 2 GB or more.
    if ($inbox !== '' && PHP_INT_SIZE === 4 && ((int)@filesize($inboxPath) <= 0 || (int)@filesize($inboxPath) >= 2147483647)) {
        return [$fields, 'This server can only handle master files under 2 GB. Please export a smaller version (e.g. ProRes Proxy or H.264) and try again.'];
    }
    if (!$existing && !has_upload($master) && $inbox === '') return [$fields, 'Please choose a master file (or pick one uploaded by FTP).'];
    if (has_upload($preview) && !isset(PREVIEW_TYPES[file_ext($preview['name'])])) return [$fields, 'The preview must be an MP4 or WebM video.'];
    if (has_upload($thumb) && (!isset(THUMB_TYPES[file_ext($thumb['name'])]) || !is_real_image($thumb['tmp_name']))) {
        return [$fields, 'The thumbnail must be a JPEG, PNG or WebP image.'];
    }

    if (has_upload($master) || $inbox !== '') {
        $name = has_upload($master) ? $master['name'] : $inbox;
        $fields['master_file'] = new_file_name($name);
        $fields['master_name'] = mb_substr(basename($name), 0, 200);
        $dest = data_path('masters', $fields['master_file']);
        $ok = has_upload($master) ? move_uploaded_file($master['tmp_name'], $dest) : rename($inboxPath, $dest);
        if (!$ok) return [$fields, 'Could not save the master file. Check that the data folder is writable.'];
        $fields['master_size'] = filesize($dest);
    }
    if (has_upload($preview)) {
        $fields['preview_file'] = new_file_name($preview['name']);
        move_uploaded_file($preview['tmp_name'], data_path('previews', $fields['preview_file']));
    } elseif ($existing && ($_POST['remove_preview'] ?? '') === '1') {
        $fields['preview_file'] = null;
    }
    if (has_upload($thumb)) {
        $fields['thumb_file'] = new_file_name($thumb['name']);
        $dest = data_path('thumbs', $fields['thumb_file']);
        move_uploaded_file($thumb['tmp_name'], $dest);
        shrink_image($dest);
    }
    return [$fields, ''];
}

if ($method === 'GET' && $path === '/admin/clips/new') render('Upload clip', view_clip_form([]));

if ($method === 'POST' && $path === '/admin/clips') {
    [$fields, $error] = save_clip_files(clip_fields_from_post(), null);
    if ($error !== '') render('Upload clip', view_clip_form($fields, $error), 400);
    $clip = clip_create($fields);
    redirect(url('/clips/' . $clip['slug']));
}

if (route('/admin/clips/([0-9a-f-]{36})(/edit|/delete)?', $path, $m)) {
    $clip = clip_get($m[1]);
    if (!$clip) not_found();
    $action = $m[2] ?? '';

    if ($method === 'GET' && $action === '/edit') render('Edit clip', view_clip_form($clip));

    if ($method === 'POST' && $action === '') {
        [$fields, $error] = save_clip_files(clip_fields_from_post(), $clip);
        if ($error !== '') render('Edit clip', view_clip_form(array_merge($clip, $fields), $error), 400);
        // Replace old files only once the new ones are safely in place.
        foreach (['master_file' => 'masters', 'preview_file' => 'previews', 'thumb_file' => 'thumbs'] as $col => $dir) {
            if (array_key_exists($col, $fields) && $fields[$col] !== $clip[$col]) remove_data_file($dir, $clip[$col]);
        }
        $clip = clip_update($clip['id'], $fields);
        redirect(url('/clips/' . $clip['slug']));
    }

    if ($method === 'POST' && $action === '/delete') {
        if (clip_has_orders($clip['id'])) {
            render('Edit clip', view_clip_form($clip, 'This clip has orders, so buyers may still need to download it. Set it to Draft to hide it instead.'), 409);
        }
        q('DELETE FROM clips WHERE id = ?', [$clip['id']]);
        remove_data_file('masters', $clip['master_file']);
        remove_data_file('previews', $clip['preview_file']);
        remove_data_file('thumbs', $clip['thumb_file']);
        redirect(url('/admin'));
    }
}

not_found();
