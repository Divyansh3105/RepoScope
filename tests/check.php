<?php

declare(strict_types=1);

// Quick regression checks, no framework: php tests/check.php (exits 1 on the first failure).

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/github.php';
require __DIR__ . '/../includes/stats.php';
require __DIR__ . '/../includes/csv.php';

$checks = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $checks;
    $checks++;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$name}\n  expected " . json_encode($expected) . "\n  got      " . json_encode($actual) . "\n");
        exit(1);
    }
}

// numbers
check('plain int', parse_number('42'), 42);
check('negative float', parse_number('-3.5'), -3.5);
check('thousands separators', parse_number('1,234,567'), 1234567);
check('misgrouped comma is text', parse_number('1,23'), null);
check('too big is not a number', parse_number('1e999'), null);
check('text', parse_number('N/A'), null);

// username rules
check('valid username', is_valid_username('octo-cat'), true);
check('hyphen at the end', is_valid_username('octocat-'), false);
check('double hyphen', is_valid_username('octo--cat'), false);
check('trailing newline', is_valid_username("octocat\n"), false);
check('40 characters', is_valid_username(str_repeat('a', 40)), false);

// CSV parsing
check('header with years stays a header', csv_parse("name,2023\nA,1\n")['table']['headers'], ['name', '2023']);
check('all-number first row is data', csv_parse("1,2\n3,4\n")['table']['headers'], ['Column 1', 'Column 2']);
check('semicolon file is flagged', count(csv_parse("a;b\n1;2\n")['notes']), 1);
check('comma file has no notes', csv_parse("a,b\n1,2\n")['notes'], []);
check('BOM and Windows-1252', csv_parse("\xEF\xBB\xBFcaf\xE9,n\nx,1\n")['table']['headers'], ['café', 'n']);

// CSV export: formulas are defused, real numbers are not
check('formula', csv_safe_cell('=1+1'), "'=1+1");
check('negative number', csv_safe_cell('-1'), '-1');
check('int', csv_safe_cell(5), '5');

// huge sums overflow to INF, which json_encode refuses
$huge = csv_parse("g,v\nx,1e308\nx,1e308\ny,5\n")['table'];
check('INF group skipped', chart_group($huge, 0, 1, 'sum', 't')['labels'], ['y']);
check('INF sum becomes null', number_stats([1e308, 1e308])['sum'], null);

// session cache: eviction, and expired entries stay for export.php but are not served
$now = 10_000;
$cache = [];
for ($i = 0; $i < CACHE_MAX_ENTRIES + 5; $i++) {
    $cache = cache_put($cache, "user:{$i}", ['n' => $i], $now);
}
check('cache is capped', count($cache), CACHE_MAX_ENTRIES);
check('oldest entry dropped', isset($cache['user:0']), false);
check('fresh entry served', cache_get($cache, 'user:7', $now + 1), ['n' => 7]);
$later = $now + CACHE_TTL + 1;
check('expired entry not served', cache_get($cache, 'user:7', $later), null);
check('expired entry kept after another put', isset(cache_put($cache, 'user:new', [], $later)['user:7']), true);

// GitHub errors
$reset = (string) ($now + 150);
check(
    'rate limit message',
    github_error_message(403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => $reset], $now),
    "GitHub's API rate limit has been reached. It resets in about 3 minutes."
);
check('not found', str_starts_with(github_error_message(404, [], $now), 'GitHub user not found'), true);
check('headers parsed', parse_http_headers(['HTTP/1.1 200 OK', 'X-RateLimit-Remaining: 7'])['headers']['x-ratelimit-remaining'], '7');

// page furniture
check('copyright starts in ' . COPYRIGHT_FROM, str_starts_with(copyright_years(), (string) COPYRIGHT_FROM), true);
check('error alert is announced', str_contains(alert_html('error', 'x'), 'role="alert"'), true);
check('success alert is a status', str_contains(alert_html('success', 'x'), 'role="status"'), true);
check('alert id is escaped', str_contains(alert_html('error', 'x', 'a"b'), 'id="a&quot;b"'), true);

echo "{$checks} checks passed\n";
