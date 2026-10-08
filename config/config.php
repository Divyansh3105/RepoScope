<?php

declare(strict_types=1);

// App settings and limits.
//
// The GitHub token is optional (it raises the limit from 60 to 5000 requests/hour).
// Set GITHUB_TOKEN in the environment, or create config/config.local.php (git-ignored):
//     <?php return ['github_token' => '...'];

$local = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];

// env var first, then config.local.php. The token only goes into requests to GitHub.
define('GITHUB_TOKEN', (string) (getenv('GITHUB_TOKEN') ?: ($local['github_token'] ?? '')));
unset($local);

// GitHub API
const GITHUB_TIMEOUT    = 10;  // seconds
const GITHUB_MAX_PAGES  = 3;   // 100 repos per page -> max 300 repos
const CACHE_TTL         = 600; // 10 min
const CACHE_MAX_ENTRIES = 30;
const COMPARE_MAX_USERS = 15;

// CSV uploads
const UPLOAD_MAX_BYTES  = 2 * 1024 * 1024; // 2 MB
const CSV_MAX_ROWS      = 5000;
const CSV_MAX_COLS      = 50;
const NUMERIC_THRESHOLD = 0.8; // share of filled cells that must be numbers
