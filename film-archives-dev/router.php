<?php
// Dev router emulating the .htaccess rules for PHP's built-in server.
$root = getenv('FA_WEBROOT');
$p = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#^/(app|data)(/|$)#', $p)) { http_response_code(403); exit('Forbidden'); }
if (preg_match('#\.(sqlite|db|log|ini)$#', $p)) { http_response_code(403); exit('Forbidden'); }
if ($p !== '/' && is_file($root . $p) && !str_ends_with($p, '.php')) return false;
if (getenv('PRETTY') === '1' && !str_starts_with($p, '/index.php')) $_SERVER['REDIRECT_FA_PRETTY'] = '1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
unset($_SERVER['PATH_INFO']);
chdir($root);
require $root . '/index.php';
