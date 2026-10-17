<?php

declare(strict_types=1);

// GitHub API client. Uses two endpoints: GET /users/{name} and GET /users/{name}/repos.
// Results are trimmed and cached in the session per user (profile + repos together).

const GITHUB_API = 'https://api.github.com';
const GITHUB_UNREADABLE = 'GitHub sent a response RepoScope could not read. Please try again.';

// $rate is the last X-RateLimit reading (null if none yet), updated from each response.
// Returns ['data' => decoded json] or ['error' => message, 'status' => http code].
function github_get(string $path, ?array &$rate): array
{
    // out of requests until the reset? fail now instead of waiting for GitHub to say no
    if ($rate !== null && $rate['remaining'] === 0 && $rate['reset'] > time()) {
        $headers = ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) $rate['reset']];
        return ['error' => github_error_message(403, $headers, time()), 'status' => 403];
    }

    $headers = [
        'User-Agent: RepoScope', // required by GitHub
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if (GITHUB_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . GITHUB_TOKEN;
    }
    $context = stream_context_create(['http' => [
        'header'        => $headers,
        'timeout'       => GITHUB_TIMEOUT,
        'ignore_errors' => true, // still read the body on 4xx/5xx
    ]]);

    // clear first so a failed request doesn't report the previous response's headers
    http_clear_last_response_headers();
    $body = file_get_contents(GITHUB_API . $path, false, $context);
    $response = parse_http_headers(http_get_last_response_headers() ?? []);

    if (isset($response['headers']['x-ratelimit-remaining'])) {
        $rate = [
            'remaining' => (int) $response['headers']['x-ratelimit-remaining'],
            'limit'     => (int) ($response['headers']['x-ratelimit-limit'] ?? 0),
            'reset'     => (int) ($response['headers']['x-ratelimit-reset'] ?? 0),
        ];
    }

    if ($body === false || $response['status'] === 0) {
        return ['error' => 'Could not reach GitHub. Check your internet connection and try again.'];
    }
    if ($response['status'] !== 200) {
        return [
            'error'  => github_error_message($response['status'], $response['headers'], time()),
            'status' => $response['status'],
        ];
    }
    try {
        return ['data' => json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
    } catch (JsonException) {
        return ['error' => GITHUB_UNREADABLE];
    }
}

// Raw header lines -> ['status' => 200, 'headers' => [lowercase name => value]].
// After a redirect there are several responses in the list; the last one wins.
function parse_http_headers(array $lines): array
{
    $status = 0;
    $headers = [];
    foreach ($lines as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match)) {
            $status = (int) $match[1];
            $headers = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $headers];
}

function github_error_message(int $status, array $headers, int $now): string
{
    // rate limited: 429, or 403 with remaining = 0 or a Retry-After header
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

function cache_get(array $cache, string $key, int $now): ?array
{
    $entry = $cache[$key] ?? null;
    return $entry !== null && $now - $entry['time'] < CACHE_TTL ? $entry['data'] : null;
}

// Stores $data, keeping at most CACHE_MAX_ENTRIES (newest last). Expired entries stay until
// pushed out: cache_get() ignores them, and export.php still needs them for open pages.
function cache_put(array $cache, string $key, array $data, int $now): array
{
    unset($cache[$key]); // so the re-added key moves to the end
    $cache[$key] = ['time' => $now, 'data' => $data];
    return array_slice($cache, -CACHE_MAX_ENTRIES, null, true);
}

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
        'joined'    => substr((string) ($user['created_at'] ?? ''), 0, 10), // just the date
    ];
}

function trim_repos(array $repos): array
{
    return array_map(fn(array $repo): array => [
        'name'     => (string) ($repo['name'] ?? ''),
        'language' => (string) ($repo['language'] ?? ''), // null when GitHub detected none
        'stars'    => (int) ($repo['stargazers_count'] ?? 0),
        'forks'    => (int) ($repo['forks_count'] ?? 0),
        'created'  => substr((string) ($repo['created_at'] ?? ''), 0, 10),
        'fork'     => (bool) ($repo['fork'] ?? false),
    ], $repos);
}

// Repos as a table, most stars first. Forks are skipped (mostly other people's code).
function repos_table(array $repos): array
{
    $rows = [];
    foreach ($repos as $repo) {
        if (!$repo['fork']) {
            $rows[] = [$repo['name'], $repo['language'], $repo['stars'], $repo['forks'], $repo['created']];
        }
    }
    usort($rows, fn(array $a, array $b): int => $b[2] <=> $a[2]);
    return ['headers' => ['Repository', 'Language', 'Stars', 'Forks', 'Created'], 'rows' => $rows];
}

// The session data github_fetch_user() works on. Pages copy it out, close the session so
// slow GitHub calls don't block their other tabs, then hand it to github_state_save().
// 'new' collects the entries fetched during this request.
function github_state(): array
{
    return [
        'cache' => $_SESSION['github_cache'] ?? [],
        'rate'  => $_SESSION['github_rate'] ?? null,
        'new'   => [],
    ];
}

// Reopens the session and stores what this request fetched. Only the new entries are merged
// in, so a lookup another tab saved in the meantime isn't overwritten. The session stays open.
function github_state_save(array $state): void
{
    start_session();
    $cache = $_SESSION['github_cache'] ?? [];
    foreach ($state['new'] as $key => $entry) {
        $cache = cache_put($cache, $key, $entry['data'], $entry['time']);
    }
    $_SESSION['github_cache'] = $cache;
    if ($state['rate'] !== null) {
        $_SESSION['github_rate'] = $state['rate'];
    }
}

// Profile + repos for one user, from $state's cache if fresh (see github_state()).
// Returns ['profile', 'repos', 'fetched', 'cached'] or ['error' => message].
function github_fetch_user(string $username, array &$state): array
{
    $key = 'user:' . strtolower($username); // prefix keeps numeric names from becoming int keys
    $now = time();
    $cached = cache_get($state['cache'], $key, $now);
    if ($cached !== null) {
        return $cached + ['cached' => true];
    }

    $path = '/users/' . rawurlencode($username);
    $user = github_get($path, $state['rate']);
    if (isset($user['error'])) {
        return $user;
    }
    if (!is_array($user['data']) || !isset($user['data']['login'])) {
        return ['error' => GITHUB_UNREADABLE];
    }
    $profile = trim_profile($user['data']);

    // public_repos tells us how many pages to ask for (none for a user with no repos)
    $repos = [];
    $pages = min(GITHUB_MAX_PAGES, (int) ceil($profile['repos'] / 100));
    for ($page = 1; $page <= $pages; $page++) {
        $result = github_get("{$path}/repos?per_page=100&sort=updated&page={$page}", $state['rate']);
        if (isset($result['error'])) {
            return $result;
        }
        if (!is_array($result['data']) || !array_is_list($result['data'])) {
            return ['error' => GITHUB_UNREADABLE];
        }
        $repos = array_merge($repos, trim_repos($result['data']));
        if (count($result['data']) < 100) {
            break;
        }
    }

    $entry = ['profile' => $profile, 'repos' => $repos, 'fetched' => $now];
    $state['cache'] = cache_put($state['cache'], $key, $entry, $now);
    $state['new'][$key] = ['time' => $now, 'data' => $entry];
    return $entry + ['cached' => false];
}

// --- Compare mode ---

// Usernames from an uploaded Compare file: the "username" (or user/login/github/handle)
// column, else the first column. Strips "@", dedupes case-insensitively, caps at COMPARE_MAX_USERS.
function compare_usernames(array $table): array
{
    $col = null;
    foreach ($table['headers'] as $i => $header) {
        if (in_array(strtolower($header), ['username', 'user', 'login', 'github', 'handle'], true)) {
            $col = $i;
            break;
        }
    }
    $cells = array_column($table['rows'], $col ?? 0);
    // no username header means the file is just a list, so csv_parse's "header" is a name too
    // (skip it if it isn't one, e.g. an auto-named "Column 1")
    if ($col === null && is_valid_username(ltrim($table['headers'][0], '@'))) {
        array_unshift($cells, $table['headers'][0]);
    }

    $users = [];
    $invalid = [];
    foreach ($cells as $cell) {
        $name = ltrim(trim((string) $cell), '@');
        if ($name === '') {
            continue;
        }
        if (!is_valid_username($name)) {
            $invalid[] = mb_substr($name, 0, 40);
            continue;
        }
        $users[strtolower($name)] ??= $name;
    }
    return ['users' => array_slice(array_values($users), 0, COMPARE_MAX_USERS), 'invalid' => $invalid, 'total' => count($users)];
}

// Leaderboard rows from github_fetch_user() results: by stars, then followers. Forks excluded.
function compare_table(array $entries): array
{
    $rows = [];
    foreach ($entries as $entry) {
        $repos = repos_table($entry['repos'])['rows'];
        $languages = array_unique(array_filter(array_column($repos, 1), fn(string $language): bool => $language !== ''));
        $rows[] = [
            $entry['profile']['login'],
            count($repos),
            array_sum(array_column($repos, 2)),
            $entry['profile']['followers'],
            count($languages)
        ];
    }
    usort($rows, fn(array $a, array $b): int => [$b[2], $b[3]] <=> [$a[2], $a[3]]);
    $ranked = array_map(fn(int $i, array $row): array => [$i + 1, ...$row], array_keys($rows), $rows);
    return ['headers' => ['Rank', 'User', 'Repositories', 'Stars', 'Followers', 'Languages'], 'rows' => $ranked];
}
