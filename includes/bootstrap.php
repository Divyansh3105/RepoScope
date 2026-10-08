<?php

declare(strict_types=1);

// Loaded first by every page in public/: error settings, includes, headers, session.

// log errors, never show them to visitors (they can leak paths)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/github.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/csv.php';

// uncaught exception: log it and show a generic error page
set_exception_handler(function (Throwable $e): void {
    error_log((string) $e);
    if (!headers_sent()) {
        http_response_code(500);
        render_header('Error');
    }
    echo '<div class="alert alert-error" role="alert"><strong>Error:</strong> '
        . 'something went wrong on our side. Please try again.</div>';
    render_footer();
});

// CSP: only our own scripts/styles/images (+ GitHub avatars), no inline scripts, no framing
header("Content-Security-Policy: default-src 'self'; img-src 'self' https://avatars.githubusercontent.com; "
    . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Render terminates HTTPS at its proxy and forwards plain HTTP with X-Forwarded-Proto.
// Spoofing that header could only turn the Secure flag on, so it's safe to trust here.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

session_start([
    'name'            => 'reposcope',
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure'   => $isHttps,
    'use_strict_mode' => true, // reject session ids we didn't issue (fixation)
]);

// new session: fresh id + CSRF token
if (!isset($_SESSION['csrf_token'])) {
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
