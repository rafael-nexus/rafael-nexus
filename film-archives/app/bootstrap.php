<?php
declare(strict_types=1);

// Optional overrides: create config.php next to index.php, e.g. to keep uploads outside the web folder:
//   <?php define('FA_DATA_DIR', '/data/rafael/home/rafael/film-archives-data');
if (is_file(__DIR__ . '/../config.php')) require __DIR__ . '/../config.php';

define('FA_ROOT', dirname(__DIR__));
if (!defined('FA_DATA_DIR')) define('FA_DATA_DIR', FA_ROOT . '/data');

const FA_DIRS = ['masters', 'previews', 'thumbs', 'inbox', 'tmp'];
foreach (FA_DIRS as $d) {
    $path = FA_DATA_DIR . '/' . $d;
    if (!is_dir($path)) @mkdir($path, 0755, true);
}

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/stripe.php';
require __DIR__ . '/media.php';
require __DIR__ . '/views.php';

function data_path(string $dir, string $file = ''): string
{
    return FA_DATA_DIR . '/' . $dir . ($file === '' ? '' : '/' . basename($file));
}

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// Scheme and host of the site, e.g. https://film-archives.com (used for Stripe redirect URLs).
function site_origin(): string
{
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// URL path of the site folder ('' when installed at the domain root, '/shop' in a subfolder).
// Worked out from the file system, because SCRIPT_NAME is unreliable behind PHP-FPM with rewrites.
function base_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $root = realpath(FA_ROOT);
    if ($docRoot && $root && str_starts_with($root . '/', rtrim($docRoot, '/') . '/')) {
        $dir = rtrim(str_replace('\\', '/', substr($root, strlen(rtrim($docRoot, '/')))), '/');
    } else {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = str_ends_with($script, '/index.php') ? rtrim(dirname($script), '/') : '';
    }
    return $dir;
}

// Pretty URLs (/clips/x) when .htaccess rewriting works, otherwise /index.php/clips/x.
function pretty_urls(): bool
{
    return getenv('FA_PRETTY') || getenv('REDIRECT_FA_PRETTY')
        || isset($_SERVER['FA_PRETTY']) || isset($_SERVER['REDIRECT_FA_PRETTY']);
}

function url(string $path = '/', array $query = []): string
{
    $u = base_dir() . (pretty_urls() ? '' : '/index.php') . $path;
    return $query ? $u . '?' . http_build_query($query) : $u;
}

function asset(string $file): string
{
    $v = @filemtime(FA_ROOT . '/assets/' . $file) ?: 1;
    return base_dir() . "/assets/$file?v=$v";
}

function redirect(string $to, int $status = 303): void
{
    header('Location: ' . $to, true, $status);
    exit;
}

function money(int $cents, ?string $currency = null): string
{
    $currency = strtoupper($currency ?? setting('currency'));
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
    $amount = number_format($cents / 100, 2, '.', ',');
    return isset($symbols[$currency]) ? $symbols[$currency] . $amount : "$amount $currency";
}

function duration_label($s): string
{
    if ($s === null || $s === '') return '';
    $s = (float)$s;
    return floor($s / 60) . ':' . str_pad((string)round(fmod($s, 60)), 2, '0', STR_PAD_LEFT);
}

function bytes_label($n): string
{
    $n = (float)$n;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
    return number_format($n, $i ? 1 : 0) . ' ' . $units[$i];
}

// Converts php.ini sizes such as "64M" to bytes.
function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}

function max_upload_bytes(): int
{
    return min(ini_bytes((string)ini_get('upload_max_filesize')), ini_bytes((string)ini_get('post_max_size')));
}

function split_tags(?string $tags): array
{
    $out = [];
    foreach (explode(',', (string)$tags) as $t) {
        $t = mb_strtolower(trim($t));
        if ($t !== '') $out[] = $t;
    }
    return array_values(array_unique($out));
}

function random_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
