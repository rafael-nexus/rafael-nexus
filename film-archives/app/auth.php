<?php
declare(strict_types=1);

// Admin login uses a signed cookie instead of PHP sessions, because session storage
// is often misconfigured or not writable on shared hosting.

const ADMIN_COOKIE = 'fa_admin';
const ADMIN_HOURS = 12;

// Random secret for signing cookies, created once and kept in the database.
function auth_secret(): string
{
    $secret = setting('session_secret');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        save_settings(['session_secret' => $secret]);
    }
    return $secret;
}

// Signature also covers the password hash, so changing the password logs out every device.
function auth_sign(string $value): string
{
    return hash_hmac('sha256', $value . '|' . setting('admin_password_hash'), auth_secret());
}

function admin_cookie_value(): ?string
{
    static $valid = null;
    if ($valid !== null) return $valid ?: null;
    $raw = (string)($_COOKIE[ADMIN_COOKIE] ?? '');
    $parts = explode('.', $raw);
    $valid = '';
    if (count($parts) === 3 && ctype_digit($parts[0]) && (int)$parts[0] > time()
        && hash_equals(auth_sign($parts[0] . '.' . $parts[1]), $parts[2])) {
        $valid = $raw;
    }
    return $valid ?: null;
}

function is_admin(): bool
{
    return admin_password_set() && admin_cookie_value() !== null;
}

function require_admin(): void
{
    if (!is_admin()) redirect(url('/admin/login', ['next' => $_SERVER['REQUEST_URI'] ?? '']), 302);
}

function set_admin_cookie(string $value, int $expires): void
{
    setcookie(ADMIN_COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function admin_login(): void
{
    $expires = time() + ADMIN_HOURS * 3600;
    $payload = $expires . '.' . bin2hex(random_bytes(8));
    set_admin_cookie($payload . '.' . auth_sign($payload), $expires);
}

function admin_logout(): void
{
    set_admin_cookie('', 1);
}

function admin_password_set(): bool
{
    return setting('admin_password_hash') !== '';
}

function check_password(string $password): bool
{
    return admin_password_set() && password_verify($password, setting('admin_password_hash'));
}

// At most 10 failed logins per IP address in 15 minutes.
function login_allowed(): bool
{
    q('DELETE FROM login_attempts WHERE at < ?', [time() - 900]);
    return (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [client_ip()])->fetchColumn() < 10;
}

function record_login_failure(): void
{
    q('INSERT INTO login_attempts (ip, at) VALUES (?, ?)', [client_ip(), time()]);
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

// CSRF protection for every admin form: a token derived from the login cookie.
function csrf_token(): string
{
    return hash_hmac('sha256', 'csrf|' . (admin_cookie_value() ?? ''), auth_secret());
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    return admin_cookie_value() !== null && hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''));
}
