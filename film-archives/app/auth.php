<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('fa_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

function is_admin(): bool
{
    if (!isset($_COOKIE['fa_session'])) return false;
    start_session();
    return ($_SESSION['admin_until'] ?? 0) > time();
}

function require_admin(): void
{
    if (!is_admin()) redirect(url('/admin/login', ['next' => $_SERVER['REQUEST_URI'] ?? '']), 302);
}

function admin_login(): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['admin_until'] = time() + 12 * 3600;
}

function admin_logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
    setcookie('fa_session', '', ['expires' => 1, 'path' => '/']);
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

// CSRF protection for every admin form.
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = random_token();
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    start_session();
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
}
