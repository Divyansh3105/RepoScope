<?php
declare(strict_types=1);

/*
 * Every page in public/ starts with:  require __DIR__ . '/../includes/bootstrap.php';
 *
 * This file:
 *   1. hides raw PHP errors from visitors,
 *   2. loads the settings and the function files,
 *   3. sends security headers,
 *   4. starts a hardened session.
 */

// 1. Errors go to the log (with `php -S` that's the terminal window), never into the page.
//    SECURITY: error messages can reveal file paths and code details to visitors.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// 2. Settings and functions. These files only define things; nothing runs until a page calls it.
require __DIR__ . '/../config/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/github.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/csv.php';

// Safety net: if an exception is thrown and no code catches it, log the details
// for us and show the visitor a friendly message instead of a stack trace.
set_exception_handler(function (Throwable $e): void {
    error_log((string) $e);
    if (!headers_sent()) { // nothing printed yet, so we can still send a whole error page
        http_response_code(500);
        render_header('Error');
    }
    echo '<div class="alert alert-error" role="alert"><strong>Error:</strong> '
        . 'something went wrong on our side. Please try again.</div>';
    render_footer();
});

// 3. Security headers (headers must be sent before any HTML).
// Content-Security-Policy: the browser may only load scripts, styles and images
// from this site (plus GitHub avatar images). Inline <script> code is blocked, so
// even if an escaping bug let someone inject HTML, their script would not run.
// frame-ancestors 'none' stops other sites from showing our pages in a frame (clickjacking).
header("Content-Security-Policy: default-src 'self'; img-src 'self' https://avatars.githubusercontent.com; "
    . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff'); // the browser must trust our Content-Type, not guess it
header('Referrer-Policy: same-origin');    // don't send our URLs (e.g. ?user=...) to other sites

// 4. Session.
// Hosts like Render handle HTTPS in a proxy and pass the request on to PHP as plain
// HTTP, adding the header "X-Forwarded-Proto: https". Trusting that header is safe
// here: faking it can only make the cookie stricter, never weaker.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

session_start([
    'name'            => 'reposcope', // our own cookie name (other apps on localhost use PHPSESSID)
    'cookie_httponly' => true,        // JavaScript can't read the cookie, which limits XSS damage
    'cookie_samesite' => 'Lax',       // the browser won't attach it to POSTs coming from other sites
    'cookie_secure'   => $isHttps,    // on HTTPS, the cookie is never sent over plain HTTP
    'use_strict_mode' => true,        // ignore session IDs this server didn't create (session fixation)
]);

// A brand-new session has no CSRF token yet: give it a fresh ID and a random token.
if (!isset($_SESSION['csrf_token'])) {
    session_regenerate_id(true);                         // true = delete the old session file
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // 64 hex characters from a secure random source
}
