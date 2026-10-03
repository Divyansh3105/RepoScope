# RepoScope

**Turn GitHub profiles and CSV files into statistics and interactive charts, using plain PHP and HTML5 Canvas.**

![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![Dependencies: none](https://img.shields.io/badge/dependencies-none-brightgreen)
![License: MIT](https://img.shields.io/badge/license-MIT-blue)

RepoScope is a data-analytics web app. Look up a GitHub user, compare a group of developers, or upload any CSV file, and RepoScope works out the statistics and draws interactive charts. It is built without frameworks, libraries or a database, to show what core PHP can do on its own.

> **Status: work in progress.** Phases 1 and 2 of 5 are done: security foundations, layout and theme, the GitHub client and Profile mode with its charts. Features marked *(Phase N)* below are still being built. See the [roadmap](#roadmap).

## Features

- **Profile mode**: enter a GitHub username to see the profile and three charts: language distribution (pie), stars per repository (bar, top 10) and a repository creation timeline (line).
- **Compare mode** *(Phase 4)*: upload a CSV of up to 15 GitHub usernames (a `username` column, or the first column). You get a leaderboard (repositories, total stars, followers, number of languages), side-by-side bar charts and a combined language chart for the group. If one user fails, the rest still load, and users already cached cost no API calls.
- **Analyze mode** *(Phase 3)*: upload any CSV. RepoScope detects which columns hold numbers and which hold text, then shows summary statistics: count, sum, min, max, mean and median for numbers, and unique count and top 10 values for text. Pick columns to draw a bar, line or pie chart.
- **Export** *(Phase 4)*: download any result table as a CSV file, generated on the fly.
- **Hand-written canvas charts**: no chart library. Charts stay sharp on high-DPI screens, redraw on resize, and have hover tooltips and readable axis labels. Pies with more than 8 categories get an "Other" slice, and colours come from the CSS theme. Every chart has an HTML table of the same data underneath for screen readers.

## Screenshots

Screenshots are added as each mode is finished.

<!-- Save images in docs/screenshots/ and uncomment the lines below.
![Home page](docs/screenshots/home.png)
![Profile mode](docs/screenshots/profile.png)
![Compare mode](docs/screenshots/compare.png)
![Analyze mode](docs/screenshots/analyze.png)
-->

## Tech stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.5: plain functions, `require`, forms and sessions. No framework, no Composer |
| Charts | HTML5 Canvas and vanilla JavaScript, no libraries |
| Styling | Plain CSS: custom properties, Grid and Flexbox |
| Storage | PHP sessions only. No database, nothing saved to disk |
| Data sources | GitHub REST API and user-uploaded CSV files |
| Testing | PHPUnit run from a single `.phar` file, plus GitHub Actions *(Phase 5)* |
| Hosting | Docker (official PHP + Apache image) on Render *(Phase 5)* |

**Constraints, on purpose:**

- No frameworks, Composer packages, Node.js, npm or build step.
- No database, and no files saved on the server. Uploads are read from PHP's temporary upload location and never moved.
- Pages are rendered by PHP, and navigation uses normal links and forms (no `fetch`).
- JavaScript is used only to draw charts.

## Architecture: one table shape

Every data source is converted into the same simple structure:

```php
[
    'headers' => ['name', 'stars', 'language'],
    'rows'    => [
        ['repo-a', 120, 'PHP'],
        ['repo-b', 45, 'JavaScript'],
    ],
]
```

GitHub data and CSV uploads both end up in this shape, so one set of statistics functions, one chart-data builder and one CSV exporter work for every mode. These functions are small and pure (data in, result out, no side effects), which makes them easy to unit test.

```text
   GitHub REST API (JSON)               CSV upload (.csv file)
            |                                    |
            v                                    v
   includes/github.php                  includes/csv.php
   fetch + session cache,               validate upload, parse with
   keep only needed fields              fgetcsv, detect headers
            |                                    |
            +-----------------+------------------+
                              |
                              v
            +------------------------------------+
            |          ONE TABLE SHAPE           |
            |  ['headers' => [...],              |
            |   'rows'    => [[...], [...]]]     |
            +------------------------------------+
                              |
         +--------------------+--------------------+
         |                    |                    |
         v                    v                    v
  includes/stats.php  chart data builder   public/export.php
  count, sum, min,    {labels, values,     CSV download with
  max, mean, median,  title} -> JSON ->    formula-injection
  top values          js/charts.js         protection
```

### Request flow

1. Every page in `public/` first loads `includes/bootstrap.php`, which sets up the settings, helper functions, security headers and session.
2. The page validates its input: fixed lists of allowed values for options, GitHub's rules for usernames, and a CSRF token for every POST.
3. Data is read from the session cache (or fetched), then converted into the table shape.
4. `session_write_close()` releases the session lock before rendering, so the user's other tabs are not kept waiting.
5. PHP renders the HTML. Chart data is embedded as JSON in `<script type="application/json">`, and `js/charts.js` draws it on a `<canvas>`.

## Security

- **Output escaping (XSS):** every value printed into HTML goes through `e()`, a wrapper around `htmlspecialchars()` with `ENT_QUOTES | ENT_SUBSTITUTE` and UTF-8.
- **Security headers:**
  - A Content Security Policy lets the browser load scripts, styles and images only from the site itself (plus GitHub avatars). Inline scripts are blocked, so even an escaping mistake can't run injected code.
  - `frame-ancestors 'none'` stops clickjacking.
  - `X-Content-Type-Options: nosniff` stops the browser guessing file types.
  - `Referrer-Policy: same-origin` keeps URLs like `?user=...` from leaking to other sites.
- **Safe links:** URLs that come from GitHub, such as avatars and personal websites, are only put into `href` or `src` after checking that they start with `http://` or `https://`. Escaping alone would still allow a `javascript:` link.
- **Safe chart data:** chart data is passed as inert JSON. It is encoded with the `JSON_HEX_*` flags so it can never break out of its `<script>` tag.
- **CSRF protection:** every POST form carries a random 64-character token stored in the session and checked with `hash_equals()`. The session cookie is also `SameSite=Lax`.
- **Session hardening:**
  - The cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS. That includes running behind Render's proxy, detected through `X-Forwarded-Proto`.
  - Strict mode rejects session IDs the server didn't create, and every new session gets a fresh ID, which blocks session fixation.
- **API key protection:** the optional GitHub token comes from the `GITHUB_TOKEN` environment variable or a git-ignored `config/config.local.php`. It is only used in request headers that PHP sends to GitHub, and is never printed or sent to the browser.
- **Upload validation** *(Phase 3)*:
  - Checks the upload error code, the `.csv` extension, a 2 MB size limit and the real MIME type (using `fileinfo`).
  - Files are read straight from PHP's temporary upload location and never moved or stored.
  - Parsing stops at 5,000 rows and 50 columns.
- **CSV formula injection** *(Phase 4)*: exported cells starting with `=`, `+`, `-`, `@`, a tab or a carriage return get a leading `'`. Spreadsheet apps then show them as text instead of running them as formulas.
- **Session caching:** GitHub data is cached in the session for 10 minutes, one entry per user (profile and repositories together), at most 30 entries, keeping only the fields that are needed. Looking at the same user again costs no API requests, and no database or server-side files are needed.
- **Input whitelisting:**
  - Chart types, column numbers and modes are checked against fixed lists.
  - Usernames are checked against GitHub's rules: 1 to 39 characters, letters, digits and single hyphens.
- **No raw errors:** PHP errors are logged but never displayed, and an uncaught exception shows a friendly error page.

## Getting started

### Requirements

- PHP 8.5 or newer with the `openssl`, `mbstring` and `fileinfo` extensions (check with `php -v` and `php -m`).
- Nothing else: no Composer, Node.js or database.

### Run locally

From the project folder:

```bash
php -S localhost:8000 -t public
```

Then open <http://localhost:8000>. The `-t public` option makes `public/` the web root, so the browser can never request files from `includes/` or `config/`.

### Optional: GitHub token

Without a token, GitHub allows 60 API requests per hour per IP address. With a token, it allows 5,000. Create a [fine-grained personal access token](https://github.com/settings/personal-access-tokens/new). It needs no extra permissions, because RepoScope only reads public data. Then do one of these:

- Set the `GITHUB_TOKEN` environment variable.
- Or create `config/config.local.php` (it is git-ignored):

```php
<?php
return ['github_token' => 'paste-your-token-here'];
```

> **Windows tip:** in `php.ini`, enable `extension=openssl`, `extension=mbstring` and `extension=fileinfo`. Also set `openssl.cafile` to a CA certificate bundle such as [cacert.pem from curl.se](https://curl.se/docs/caextract.html). Without it, PHP can't verify GitHub's HTTPS certificate.

## Running tests

*(Phase 5)* Unit tests use PHPUnit, run from a single `.phar` file (no Composer). They will cover:

- username validation and numeric column detection
- every statistics function
- CSV parsing edge cases (BOM, empty cells, short rows)
- the formula-injection sanitizer
- conversion of GitHub data into the table shape

They use saved JSON fixtures instead of calling the real API. Instructions will be added with the tests.

## Deployment

*(Phase 5)* RepoScope will ship with a `Dockerfile` based on the official PHP + Apache image, with the document root set to `public/`. It will be ready to deploy as a Docker web service on [Render](https://render.com), with the GitHub token set there as the `GITHUB_TOKEN` environment variable.

## Project structure

```text
RepoScope/
├── public/                    web root: the only folder the browser can reach
│   ├── index.php              home page: pick a mode
│   ├── profile.php            Profile mode
│   ├── analyze.php            Analyze mode *
│   ├── compare.php            Compare mode *
│   ├── export.php             CSV download *
│   ├── js/charts.js           canvas chart functions
│   ├── css/style.css          dark theme and chart colours
│   └── favicon.svg
├── includes/
│   ├── bootstrap.php          error handling, security headers, session
│   ├── helpers.php            escaping, validation, CSRF
│   ├── layout.php             header, navigation, footer
│   ├── github.php             GitHub API client and session cache
│   ├── csv.php                upload validation, parsing, export *
│   └── stats.php              chart-data builders (statistics and type detection *)
├── config/
│   ├── config.php             limits and defaults
│   └── config.local.php       your GitHub token (optional, git-ignored)
├── tests/                     PHPUnit tests *
├── .github/workflows/ci.yml   GitHub Actions workflow *
├── Dockerfile                 container image for Render *
├── LICENSE
└── README.md
```

`*` = added in a later phase (see the roadmap).

## Roadmap

- [x] **Phase 1:** config, bootstrap (error handling, security headers, session), helpers, layout, home page, CSS theme
- [x] **Phase 2:** GitHub API client with session cache, Profile mode, canvas charts
- [ ] **Phase 3:** CSV upload and parsing, statistics, Analyze mode
- [ ] **Phase 4:** Compare mode, CSV export with formula-injection protection
- [ ] **Phase 5:** PHPUnit tests, GitHub Actions CI, Dockerfile, deployment

## What I learned

<!-- Draft based on what came up while building. Rewrite it in your own words as you go. -->

- PHP locks the session file for the whole request, so calling `session_write_close()` early keeps other tabs from waiting.
- Security works in layers: escaping output *and* a Content Security Policy, CSRF tokens *and* `SameSite` cookies.
- What session fixation is, and how `session.use_strict_mode` plus `session_regenerate_id()` prevent it.
- Behind a reverse proxy such as Render, PHP only sees plain HTTP. The original scheme arrives in the `X-Forwarded-Proto` header.
- Languages keep moving: PHP 8.5 deprecates `$http_response_header` in favour of `http_get_last_response_headers()`.
- How to pick chart colours that stay distinguishable for colour-blind users, and check them with a validator instead of by eye.
- How to run a modern PHP next to XAMPP on Windows: `php.ini`, extensions and CA certificates.
- A canvas needs its pixel buffer scaled by `devicePixelRatio`, or charts look blurry on high-DPI screens.
- Tooltips on a canvas mean doing your own hit-testing: working out which bar, slice or point is under the pointer.
- GitHub allows 60 unauthenticated requests per hour per IP address, shared with everything else on the same network, so caching and counting requests matter.

## License

[MIT](LICENSE) © 2026 Divyansh Garg
