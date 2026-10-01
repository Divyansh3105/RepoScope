<?php
declare(strict_types=1);

/*
 * RepoScope settings. Every limit the app enforces lives here, in one place.
 *
 * GitHub token (optional): raises the API limit from 60 to 5,000 requests per hour.
 *   - On a server (Docker, Render): set the GITHUB_TOKEN environment variable.
 *   - On your machine: create config/config.local.php (it is git-ignored) containing:
 *
 *         <?php
 *         return ['github_token' => 'paste-your-token-here'];
 */

$local = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];

// The environment variable wins, then config.local.php, then "no token".
// SECURITY: the token is only ever put in the Authorization header of requests
// that PHP sends to GitHub. It is never printed into a page or sent to the browser.
define('GITHUB_TOKEN', (string) (getenv('GITHUB_TOKEN') ?: ($local['github_token'] ?? '')));
unset($local); // this file runs in the page's scope, so don't leave a stray variable behind

// GitHub API
const GITHUB_TIMEOUT    = 10;  // seconds to wait for each API request
const GITHUB_MAX_PAGES  = 3;   // repos arrive 100 per page, so at most 300 repos per user
const CACHE_TTL         = 600; // seconds an API result stays cached (10 minutes)
const CACHE_MAX_ENTRIES = 30;  // when the cache is full, the oldest entry is removed
const COMPARE_MAX_USERS = 15;  // unique usernames per Compare upload

// CSV uploads
const UPLOAD_MAX_BYTES  = 2 * 1024 * 1024; // 2 MB
const CSV_MAX_ROWS      = 5000;
const CSV_MAX_COLS      = 50;
const NUMERIC_THRESHOLD = 0.8; // a column is numeric when >= 80% of its non-empty values are numbers
