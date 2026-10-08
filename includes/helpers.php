<?php

declare(strict_types=1);

// Escape for HTML output (text and attribute values). ENT_SUBSTITUTE keeps bad UTF-8
// from turning the whole string into ''.
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token'] ?? '') . '">';
}

function csrf_is_valid(mixed $submitted): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';

    // hash_equals is constant-time
    return $expected !== '' && is_string($submitted) && hash_equals($expected, $submitted);
}

// GitHub's rules: 1-39 chars, letters/digits/single hyphens, no hyphen at the start or end.
function is_valid_username(string $username): bool
{
    // \z instead of $ so a trailing newline doesn't pass
    return preg_match('/^[a-z\d](?:[a-z\d]|-(?=[a-z\d])){0,38}\z/i', $username) === 1;
}

// Only allow http(s) links, so a "javascript:" URL from GitHub can't end up in an href.
function safe_url(string $url): string
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

// Whitelist a request value: pick($_GET['type'] ?? null, ['bar', 'line', 'pie'], 'bar')
function pick(mixed $value, array $allowed, string $default): string
{
    return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
}
