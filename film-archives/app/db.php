<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . FA_DATA_DIR . '/film-archives.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS clips (
        id            TEXT PRIMARY KEY,
        slug          TEXT NOT NULL UNIQUE,
        title         TEXT NOT NULL,
        description   TEXT NOT NULL DEFAULT '',
        year          INTEGER,
        location      TEXT NOT NULL DEFAULT '',
        tags          TEXT NOT NULL DEFAULT '',
        source_format TEXT NOT NULL DEFAULT '',
        color         TEXT NOT NULL DEFAULT '',
        sound         TEXT NOT NULL DEFAULT '',
        duration_sec  REAL,
        resolution    TEXT NOT NULL DEFAULT '',
        price_cents   INTEGER NOT NULL,
        status        TEXT NOT NULL DEFAULT 'published',
        master_file   TEXT NOT NULL,
        master_name   TEXT NOT NULL,
        master_size   INTEGER NOT NULL,
        preview_file  TEXT,
        thumb_file    TEXT,
        created_at    TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE TABLE IF NOT EXISTS orders (
        id                TEXT PRIMARY KEY,
        clip_id           TEXT NOT NULL REFERENCES clips(id) ON DELETE RESTRICT,
        email             TEXT NOT NULL,
        amount_cents      INTEGER NOT NULL,
        currency          TEXT NOT NULL,
        status            TEXT NOT NULL DEFAULT 'pending',
        stripe_session_id TEXT,
        download_token    TEXT NOT NULL UNIQUE,
        downloads         INTEGER NOT NULL DEFAULT 0,
        created_at        TEXT NOT NULL DEFAULT (datetime('now')),
        paid_at           TEXT
      );
      CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, at INTEGER NOT NULL);
    ");
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// ---------- Settings ----------

const DEFAULT_SETTINGS = [
    'site_name' => 'Film Archives',
    'tagline' => 'Rare archival footage, digitised and licensed for your productions.',
    'license_text' => "Each purchase grants a non-exclusive, worldwide, perpetual licence to use the clip in one production (film, broadcast, online video or advertising). Resale or redistribution of the raw footage is not permitted. Contact us for exclusive or multi-production licences.",
    'imprint_text' => "Operator name\nStreet and number\nPostcode and city\nCountry\n\nEmail: contact@film-archives.com",
    'contact_email' => 'contact@film-archives.com',
    'currency' => 'eur',
    'stripe_secret_key' => '',
    'stripe_webhook_secret' => '',
    'download_ttl_hours' => '72',
    'max_downloads' => '5',
    'admin_password_hash' => '',
];

function settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = DEFAULT_SETTINGS;
        foreach (q('SELECT key, value FROM settings')->fetchAll() as $r) $cache[$r['key']] = $r['value'];
    }
    return $cache;
}

function setting(string $key): string
{
    return (string)(settings()[$key] ?? '');
}

function save_settings(array $values): void
{
    $st = db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    foreach ($values as $k => $v) {
        if (array_key_exists($k, DEFAULT_SETTINGS)) $st->execute([$k, trim((string)$v)]);
    }
}

// ---------- Clips ----------

const CLIP_FIELDS = ['title', 'description', 'year', 'location', 'tags', 'source_format', 'color', 'sound',
    'duration_sec', 'resolution', 'price_cents', 'status', 'master_file', 'master_name', 'master_size',
    'preview_file', 'thumb_file'];

function slugify(string $title): string
{
    $s = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT', $title) : $title;
    $base = trim(substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), 0, 60), '-') ?: 'clip';
    $slug = $base;
    for ($n = 2; q('SELECT 1 FROM clips WHERE slug = ?', [$slug])->fetchColumn(); $n++) $slug = "$base-$n";
    return $slug;
}

function clip_create(array $data): array
{
    $row = ['id' => uuid(), 'slug' => slugify($data['title'])];
    foreach (CLIP_FIELDS as $f) $row[$f] = $data[$f] ?? null;
    $cols = array_keys($row);
    q('INSERT INTO clips (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(fn($c) => ":$c", $cols)) . ')', $row);
    return clip_get($row['id']);
}

function clip_update(string $id, array $data): array
{
    $cols = array_values(array_filter(CLIP_FIELDS, fn($f) => array_key_exists($f, $data)));
    $params = ['id' => $id];
    foreach ($cols as $c) $params[$c] = $data[$c];
    if ($cols) {
        q('UPDATE clips SET ' . implode(', ', array_map(fn($c) => "$c = :$c", $cols)) . ", updated_at = datetime('now') WHERE id = :id", $params);
    }
    return clip_get($id);
}

function clip_get(string $id): ?array
{
    return q('SELECT * FROM clips WHERE id = ?', [$id])->fetch() ?: null;
}

function clip_by_slug(string $slug): ?array
{
    return q('SELECT * FROM clips WHERE slug = ?', [$slug])->fetch() ?: null;
}

function clips_all(): array
{
    return q('SELECT * FROM clips ORDER BY created_at DESC, rowid DESC')->fetchAll();
}

function clips_search(string $q, string $decade, string $tag): array
{
    $where = ["status = 'published'"];
    $p = [];
    if ($q !== '') {
        $where[] = '(title LIKE :q OR description LIKE :q OR tags LIKE :q OR location LIKE :q)';
        $p['q'] = "%$q%";
    }
    if (preg_match('/^\d{4}$/', $decade)) {
        $where[] = 'year BETWEEN :d0 AND :d1';
        $p['d0'] = (int)$decade;
        $p['d1'] = (int)$decade + 9;
    }
    if ($tag !== '') {
        $where[] = "(',' || REPLACE(LOWER(tags), ', ', ',') || ',') LIKE :tag";
        $p['tag'] = '%,' . mb_strtolower($tag) . ',%';
    }
    return q('SELECT * FROM clips WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, rowid DESC', $p)->fetchAll();
}

function clip_decades(): array
{
    return q("SELECT DISTINCT (year / 10) * 10 AS d FROM clips WHERE status = 'published' AND year IS NOT NULL ORDER BY d")
        ->fetchAll(PDO::FETCH_COLUMN);
}

function clip_tags(): array
{
    $counts = [];
    foreach (q("SELECT tags FROM clips WHERE status = 'published'")->fetchAll(PDO::FETCH_COLUMN) as $tags) {
        foreach (split_tags($tags) as $t) $counts[$t] = ($counts[$t] ?? 0) + 1;
    }
    arsort($counts);
    return array_slice(array_map('strval', array_keys($counts)), 0, 20);
}

function clip_has_orders(string $id): bool
{
    return (bool)q('SELECT 1 FROM orders WHERE clip_id = ? LIMIT 1', [$id])->fetchColumn();
}

// ---------- Orders ----------

function order_create(string $clipId, string $email, int $amount, string $currency): array
{
    $id = uuid();
    q('INSERT INTO orders (id, clip_id, email, amount_cents, currency, download_token) VALUES (?, ?, ?, ?, ?, ?)',
        [$id, $clipId, $email, $amount, $currency, random_token()]);
    return order_get($id);
}

function order_get(string $id): ?array
{
    return q('SELECT * FROM orders WHERE id = ?', [$id])->fetch() ?: null;
}

function order_by_token(string $token): ?array
{
    return q('SELECT * FROM orders WHERE download_token = ?', [$token])->fetch() ?: null;
}

function order_mark_paid(string $id): void
{
    q("UPDATE orders SET status = 'paid', paid_at = datetime('now') WHERE id = ? AND status != 'paid'", [$id]);
}

function orders_all(): array
{
    return q('SELECT o.*, c.title AS clip_title, c.slug AS clip_slug FROM orders o JOIN clips c ON c.id = o.clip_id
              ORDER BY o.created_at DESC, o.rowid DESC LIMIT 200')->fetchAll();
}

function order_stats(): array
{
    return q("SELECT COUNT(*) AS paid_count, COALESCE(SUM(amount_cents), 0) AS revenue_cents FROM orders WHERE status = 'paid'")->fetch();
}

// Download links expire after a set time and number of uses.
function download_state(array $order): array
{
    $paidAt = $order['paid_at'] ? strtotime($order['paid_at'] . ' UTC') : 0;
    return [
        'expired' => time() - $paidAt > (int)setting('download_ttl_hours') * 3600,
        'left' => (int)setting('max_downloads') - (int)$order['downloads'],
    ];
}
