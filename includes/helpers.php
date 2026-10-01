<?php
declare(strict_types=1);

/**
 * Escape a value for HTML. Use it for EVERY value printed into a page.
 *
 * ENT_QUOTES also escapes ' and ", so the result is safe inside attribute values.
 * ENT_SUBSTITUTE replaces invalid UTF-8 with the � character. Without it,
 * htmlspecialchars() returns an empty string and the value silently disappears.
 */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Hidden input carrying this session's CSRF token. Put it inside every POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token'] ?? '') . '">';
}

/**
 * True when the token sent with a POST form matches this session's token.
 *
 * SECURITY: another website can make your browser submit a form to RepoScope,
 * but it can't read the token from our pages, so its forged request fails here.
 */
function csrf_is_valid(mixed $submitted): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';

    // hash_equals() takes the same time whether the first or last character differs,
    // so an attacker can't guess the token by measuring response times.
    return $expected !== '' && is_string($submitted) && hash_equals($expected, $submitted);
}

/** GitHub username rules: 1-39 characters, letters, digits and single hyphens, no hyphen at either end. */
function is_valid_username(string $username): bool
{
    // Every hyphen must be followed by a letter or digit, which rules out "--" and a trailing "-".
    // \z means "end of string". Unlike $, it does not let a trailing newline slip through.
    return preg_match('/^[a-z\d](?:[a-z\d]|-(?=[a-z\d])){0,38}\z/i', $username) === 1;
}

/**
 * Whitelist check for values from $_GET/$_POST: returns $value only if it is
 * exactly one of $allowed, otherwise $default.
 * Example: pick($_GET['type'] ?? null, ['bar', 'line', 'pie'], 'bar')
 */
function pick(mixed $value, array $allowed, string $default): string
{
    return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
}
