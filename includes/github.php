<?php
declare(strict_types=1);

/*
 * GitHub API client. Only two endpoints are used:
 *
 *     GET /users/{username}                                    the profile
 *     GET /users/{username}/repos?per_page=100&sort=updated    repositories, up to 3 pages
 *
 * Responses are trimmed to the fields RepoScope needs and cached in the session,
 * one entry per user (profile + repos together). Looking at the same user again
 * within CACHE_TTL seconds costs no API requests.
 */

const GITHUB_API = 'https://api.github.com';
const GITHUB_UNREADABLE = 'GitHub sent a response RepoScope could not read. Please try again.';

/**
 * Sends one GET request to the GitHub API.
 * Returns ['data' => the decoded JSON] on success, or ['error' => a friendly message].
 */
function github_get(string $path): array
{
    $headers = [
        'User-Agent: RepoScope',               // GitHub rejects requests without a User-Agent
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if (GITHUB_TOKEN !== '') {
        // SECURITY: this is the only place the token goes, from our server straight to GitHub.
        $headers[] = 'Authorization: Bearer ' . GITHUB_TOKEN;
    }
    $context = stream_context_create(['http' => [
        'header'        => $headers,
        'timeout'       => GITHUB_TIMEOUT,
        'ignore_errors' => true, // also hand us the body of 404/403 replies instead of just failing
    ]]);

    // PHP 8.4+ keeps the last response's headers for us. Clear them first, so a request
    // that never gets an answer can't report the previous request's headers.
    http_clear_last_response_headers();
    $body = file_get_contents(GITHUB_API . $path, false, $context);
    $response = parse_http_headers(http_get_last_response_headers() ?? []);

    // Remember GitHub's rate-limit counters so pages can show how many requests are left.
    if (isset($response['headers']['x-ratelimit-remaining'])) {
        $_SESSION['github_rate'] = [
            'remaining' => (int) $response['headers']['x-ratelimit-remaining'],
            'limit'     => (int) ($response['headers']['x-ratelimit-limit'] ?? 0),
            'reset'     => (int) ($response['headers']['x-ratelimit-reset'] ?? 0), // Unix timestamp
        ];
    }

    if ($body === false || $response['status'] === 0) {
        return ['error' => 'Could not reach GitHub. Check your internet connection and try again.'];
    }
    if ($response['status'] !== 200) {
        return ['error' => github_error_message($response['status'], $response['headers'], time())];
    }
    try {
        return ['data' => json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
    } catch (JsonException) {
        return ['error' => GITHUB_UNREADABLE];
    }
}

/**
 * Turns raw header lines into ['status' => 200, 'headers' => ['x-ratelimit-remaining' => '59', ...]].
 * Header names are lower-cased. After a redirect the list holds several responses; the last one wins.
 */
function parse_http_headers(array $lines): array
{
    $status = 0;
    $headers = [];
    foreach ($lines as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match)) {
            $status = (int) $match[1]; // a status line starts a new response
            $headers = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $headers];
}

/** A friendly explanation of a failed API response. $now is passed in so tests can control the time. */
function github_error_message(int $status, array $headers, int $now): string
{
    // Rate limited: GitHub answers 429, or 403 with no requests remaining or a Retry-After header.
    $remaining = $headers['x-ratelimit-remaining'] ?? null;
    if ($status === 429 || ($status === 403 && ($remaining === '0' || isset($headers['retry-after'])))) {
        $seconds = isset($headers['retry-after'])
            ? (int) $headers['retry-after']
            : (int) ($headers['x-ratelimit-reset'] ?? $now) - $now;
        $minutes = max(1, (int) ceil($seconds / 60));
        return "GitHub's API rate limit has been reached. It resets in about {$minutes} "
            . ($minutes === 1 ? 'minute.' : 'minutes.');
    }

    return match (true) {
        $status === 404 => 'GitHub user not found. Check the spelling and try again.',
        $status === 401 => 'GitHub rejected the access token. It may be wrong or expired.',
        $status >= 500  => 'GitHub is having problems right now. Please try again in a few minutes.',
        default         => "GitHub refused the request (HTTP {$status}). Please try again later.",
    };
}

/** The cached data for $key if it was stored less than CACHE_TTL seconds ago, otherwise null. */
function cache_get(array $cache, string $key, int $now): ?array
{
    $entry = $cache[$key] ?? null;
    return $entry !== null && $now - $entry['time'] < CACHE_TTL ? $entry['data'] : null;
}

/**
 * Returns the cache with $data stored under $key. Expired entries are dropped and,
 * if more than CACHE_MAX_ENTRIES remain, the oldest ones go first.
 */
function cache_put(array $cache, string $key, array $data, int $now): array
{
    unset($cache[$key]); // PHP arrays keep insertion order, so re-adding a key makes it the newest
    $cache[$key] = ['time' => $now, 'data' => $data];
    $cache = array_filter($cache, fn(array $entry): bool => $now - $entry['time'] < CACHE_TTL);
    return array_slice($cache, -CACHE_MAX_ENTRIES, null, true); // keep the newest entries
}

/** Keeps only the profile fields RepoScope shows. */
function trim_profile(array $user): array
{
    return [
        'login'     => (string) ($user['login'] ?? ''),
        'name'      => (string) ($user['name'] ?? ''),
        'avatar'    => (string) ($user['avatar_url'] ?? ''),
        'bio'       => (string) ($user['bio'] ?? ''),
        'company'   => (string) ($user['company'] ?? ''),
        'location'  => (string) ($user['location'] ?? ''),
        'blog'      => (string) ($user['blog'] ?? ''),
        'repos'     => (int) ($user['public_repos'] ?? 0),
        'followers' => (int) ($user['followers'] ?? 0),
        'following' => (int) ($user['following'] ?? 0),
        'joined'    => substr((string) ($user['created_at'] ?? ''), 0, 10), // "2011-01-25T18:44:36Z" → "2011-01-25"
    ];
}

/** Keeps only the repository fields RepoScope needs. */
function trim_repos(array $repos): array
{
    return array_map(fn(array $repo): array => [
        'name'     => (string) ($repo['name'] ?? ''),
        'language' => (string) ($repo['language'] ?? ''), // GitHub sends null when it detected no language
        'stars'    => (int) ($repo['stargazers_count'] ?? 0),
        'forks'    => (int) ($repo['forks_count'] ?? 0),
        'created'  => substr((string) ($repo['created_at'] ?? ''), 0, 10),
        'fork'     => (bool) ($repo['fork'] ?? false),
    ], $repos);
}

/**
 * Repositories → the app's one table shape, most-starred first.
 * Forks are left out: they are mostly other people's code.
 */
function repos_table(array $repos): array
{
    $rows = [];
    foreach ($repos as $repo) {
        if (!$repo['fork']) {
            $rows[] = [$repo['name'], $repo['language'], $repo['stars'], $repo['forks'], $repo['created']];
        }
    }
    usort($rows, fn(array $a, array $b): int => $b[2] <=> $a[2]); // column 2 = Stars, highest first
    return ['headers' => ['Repository', 'Language', 'Stars', 'Forks', 'Created'], 'rows' => $rows];
}

/**
 * Profile and repositories for one user: from the session cache when fresh, otherwise from GitHub.
 * Returns ['profile' => [...], 'repos' => [...], 'fetched' => timestamp, 'cached' => bool],
 * or ['error' => a friendly message]. Needs an open session, so call it before session_write_close().
 */
function github_fetch_user(string $username): array
{
    $key = 'user:' . strtolower($username); // usernames are case-insensitive; the prefix keeps keys strings
    $now = time();
    $cached = cache_get($_SESSION['github_cache'] ?? [], $key, $now);
    if ($cached !== null) {
        return $cached + ['cached' => true];
    }

    $path = '/users/' . rawurlencode($username);
    $user = github_get($path);
    if (isset($user['error'])) {
        return $user;
    }
    if (!is_array($user['data']) || !isset($user['data']['login'])) {
        return ['error' => GITHUB_UNREADABLE];
    }
    $profile = trim_profile($user['data']);

    // Repositories come 100 per page. public_repos says how many pages there are,
    // so a user without repositories costs no second request.
    $repos = [];
    $pages = min(GITHUB_MAX_PAGES, (int) ceil($profile['repos'] / 100));
    for ($page = 1; $page <= $pages; $page++) {
        $result = github_get("{$path}/repos?per_page=100&sort=updated&page={$page}");
        if (isset($result['error'])) {
            return $result; // nothing is cached, so the next attempt starts fresh
        }
        if (!is_array($result['data']) || !array_is_list($result['data'])) {
            return ['error' => GITHUB_UNREADABLE];
        }
        $repos = array_merge($repos, trim_repos($result['data']));
        if (count($result['data']) < 100) {
            break; // a short page is the last page
        }
    }

    $entry = ['profile' => $profile, 'repos' => $repos, 'fetched' => $now];
    $_SESSION['github_cache'] = cache_put($_SESSION['github_cache'] ?? [], $key, $entry, $now);
    return $entry + ['cached' => false];
}
