<?php

declare(strict_types=1);

// Entry point for PHP's built-in server: php -S localhost:8000 -t public public/router.php
// Real files are served as they are; everything else gets the 404 page. Without a router,
// the built-in server answers unknown addresses with index.php and a 200.

$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}
// browsers ask for /favicon.ico whatever the page says; the icon is an SVG
if ($path === '/favicon.ico') {
    header('Content-Type: image/svg+xml');
    readfile(__DIR__ . '/favicon.svg');
    return true;
}
// realpath() resolves any ../ so a request can't reach outside public/
$file = realpath(__DIR__ . $path);
if (
    $file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
    && $file !== __FILE__ && !str_starts_with(basename($file), '.') // not the router or .htaccess
) {
    return false;
}
require __DIR__ . '/404.php';
return true;
