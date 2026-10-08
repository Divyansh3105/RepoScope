# RepoScope

**Turn GitHub profiles and CSV files into statistics and interactive charts, using plain PHP and HTML5 Canvas.**

![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![Dependencies: none](https://img.shields.io/badge/dependencies-none-brightgreen)
![License: MIT](https://img.shields.io/badge/license-MIT-blue)

RepoScope is a data-analytics web app. Look up a GitHub user, compare a group of developers, or upload any CSV file, and RepoScope works out the statistics and draws interactive charts. It is built without frameworks or a database, to show what core PHP can do on its own; the only library is a self-hosted GSAP file for chart animation.

> **Status: work in progress.** Phases 1 to 4 of 5 are done: security foundations, layout and theme, the GitHub client, Profile, Analyze and Compare modes, and CSV export. Tests and deployment _(Phase 5)_ are still being built. See the [roadmap](#roadmap).

## Features

- **Profile mode**: enter a GitHub username to see the profile and three charts: language distribution (pie), stars per repository (ranked horizontal bars, top 10) and a repository creation timeline (line).
- **Compare mode**: upload a CSV of up to 15 GitHub usernames (a `username` column, or the first column; a [template](public/compare-template.csv) is included). You get a leaderboard ranked by stars (repositories, total stars, followers, number of languages), ranked bar charts for stars, followers and repositories, and a combined language chart for the group. A leading `@` and repeated names are fine. If one user fails, the rest still load and the page says why, and users already cached cost no API calls.
- **Analyze mode**: upload any CSV. RepoScope detects which columns hold numbers and which hold text, then shows summary statistics: count, sum, min, max, mean and median for numbers, and unique count and top 10 values for text. Pick a column to group by and a count, sum or average to draw a bar, line or pie chart, and preview the first 100 rows.
- **Export**: every result table has an "Export CSV" link: a profile's repositories, the Compare leaderboard, and Analyze's data and statistics tables. The file is generated on the fly from the session, opens correctly in Excel (UTF-8 with a byte order mark), and is protected against formula injection.
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

| Layer        | Choice                                                                                                                    |
| ------------ | ------------------------------------------------------------------------------------------------------------------------- |
| Backend      | PHP 8.5: plain functions, `require`, forms and sessions. No framework, no Composer                                        |
| Charts       | HTML5 Canvas and vanilla JavaScript, no chart library                                                                     |
| Motion       | [GSAP](https://gsap.com) 3.15, self-hosted (one file, no npm), for the chart draw-in; CSS transitions for everything else |
| Styling      | Plain CSS: custom properties, Grid and Flexbox                                                                            |
| Storage      | PHP sessions only. No database, nothing saved to disk                                                                     |
| Data sources | GitHub REST API and user-uploaded CSV files                                                                               |
| Testing      | PHPUnit run from a single `.phar` file, plus GitHub Actions _(Phase 5)_                                                   |
| Hosting      | Docker (official PHP + Apache image) on Render _(Phase 5)_                                                                |

**Constraints, on purpose:**

- No frameworks, Composer packages, Node.js, npm or build step.
- No database, and no files saved on the server. Uploads are read from PHP's temporary upload location and never moved.
- Pages are rendered by PHP, and navigation uses normal links and forms (no `fetch`).
- JavaScript draws the charts and adds small interface feedback (a "Looking up…" button state). Pages work without it: every chart has a data table, and every form submits normally.

## Design

The interface follows one visual idea, **the transit line system**. Every page has a black sign band with a white rule, and actions sit on white sign plates. Each category (a programming language, a CSV value) is a "line" with its own coloured bullet, and line charts are drawn as routes with stations. Colours and fonts live as CSS custom properties at the top of [`public/css/style.css`](public/css/style.css).

It was designed with four public guides:

- [Impeccable](https://impeccable.style) for the direction and its quality floor.
- [transitions.dev](https://transitions.dev) for motion timings and the tooltip, disclosure, error-shake and text-swap recipes.
- [make-interfaces-feel-better](https://github.com/jakubkrehel/make-interfaces-feel-better) for the details: press scale, hit areas, outlines, text wrapping.
- The official [GSAP skills](https://github.com/greensock/gsap-skills) for the chart animation.

Motion is deliberately small. Charts draw themselves in only when fresh data arrives from GitHub, never on a cached view, and everything moving is switched off for visitors who prefer reduced motion.

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
- **Upload validation:**
  - Checks the upload error code, `is_uploaded_file()`, the `.csv` extension, a 2 MB size limit and the real MIME type (using `fileinfo`), plus a CSRF token on the upload form.
  - Files are read straight from PHP's temporary upload location and never moved or stored.
  - Parsing stops at 5,000 rows and 50 columns. Files saved by Excel in Windows-1252 are converted to UTF-8.
  - After an upload the page redirects (Post/Redirect/Get), so refreshing never sends the file twice.
- **CSV formula injection:** exported cells starting with `=`, `+`, `-`, `@`, a tab or a carriage return get a leading `'`. Spreadsheet apps then show them as text instead of running them as formulas. Real numbers such as `-3.5` are left alone, since a spreadsheet never reads them as formulas.
- **Safe exports:** `export.php` only sends tables already in the visitor's own session, picked from a fixed list of names. Nothing from the URL becomes a file path, and the download file name is built from checked characters only.
- **Session caching:** GitHub data is cached in the session for 10 minutes, one entry per user (profile and repositories together), at most 30 entries, keeping only the fields that are needed. Looking at the same user again costs no API requests, and no database or server-side files are needed. Once GitHub reports the hourly limit used up, RepoScope stops asking until the reset time, so a Compare upload fails fast instead of waiting on every user.
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

### Or run it in Docker

No local PHP needed, only Docker. From the project folder (on Windows PowerShell, use `${PWD}` instead of `$(pwd)`):

```bash
docker run --rm -p 8000:8000 -v "$(pwd):/app" -w /app php:8.5-cli php -S 0.0.0.0:8000 -t public
```

The official image already includes the `openssl`, `mbstring` and `fileinfo` extensions, and your edits show up straight away because the folder is mounted.

### Optional: GitHub token

Without a token, GitHub allows 60 API requests per hour per IP address. With a token, it allows 5,000. Create a [fine-grained personal access token](https://github.com/settings/personal-access-tokens/new). It needs no extra permissions, because RepoScope only reads public data. Then do one of these:

- Set the `GITHUB_TOKEN` environment variable.
- Or create `config/config.local.php` (it is git-ignored):

```php
<?php
return ['github_token' => 'paste-your-token-here'];
```

> **Windows tip:** in `php.ini`, enable `extension=openssl`, `extension=mbstring` and `extension=fileinfo`. Also set `openssl.cafile` to a CA certificate bundle such as [cacert.pem from curl.se](https://curl.se/docs/caextract.html). Without it, PHP can't verify GitHub's HTTPS certificate.

> **Windows tip:** if PHP warns that "An Application Control policy has blocked" `php_openssl.dll`, Windows Smart App Control is blocking the unsigned extension, and every GitHub lookup fails with "Could not reach GitHub". Use the Docker command above instead.

## Running tests

_(Phase 5)_ Unit tests use PHPUnit, run from a single `.phar` file (no Composer). They will cover:

- username validation and numeric column detection
- every statistics function
- CSV parsing edge cases (BOM, empty cells, short rows)
- the formula-injection sanitizer
- conversion of GitHub data into the table shape

They use saved JSON fixtures instead of calling the real API. Instructions will be added with the tests.

## Deployment

_(Phase 5)_ RepoScope will ship with a `Dockerfile` based on the official PHP + Apache image, with the document root set to `public/`. It will be ready to deploy as a Docker web service on [Render](https://render.com), with the GitHub token set there as the `GITHUB_TOKEN` environment variable.

## Project structure

```text
RepoScope/
├── public/                    web root: the only folder the browser can reach
│   ├── index.php              home page: pick a mode
│   ├── profile.php            Profile mode
│   ├── analyze.php            Analyze mode
│   ├── compare.php            Compare mode
│   ├── export.php             CSV download of any result table
│   ├── compare-template.csv   example file for Compare mode
│   ├── js/charts.js           canvas chart functions
│   ├── js/ui.js               form feedback ("Looking up…")
│   ├── js/vendor/gsap.min.js  GSAP 3.15 (own licence, see below)
│   ├── css/style.css          the transit theme and chart colours
│   ├── fonts/                 Hanken Grotesk (self-hosted, OFL licence inside)
│   └── favicon.svg
├── includes/
│   ├── bootstrap.php          error handling, security headers, session
│   ├── helpers.php            escaping, validation, CSRF
│   ├── layout.php             header, navigation, footer
│   ├── github.php             GitHub API client, session cache, Compare leaderboard
│   ├── csv.php                upload validation, parsing and safe CSV writing
│   └── stats.php              statistics, column type detection, chart-data builders
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
- [x] **Phase 3:** CSV upload and parsing, statistics, Analyze mode
- [x] **Phase 4:** Compare mode, CSV export with formula-injection protection
- [ ] **Phase 5:** PHPUnit tests, GitHub Actions CI, Dockerfile, deployment

## What I learned

<!-- Draft based on what came up while building. Rewrite it in your own words as you go. -->

- PHP locks the session file for the whole request, so calling `session_write_close()` early keeps other tabs from waiting.
- Security works in layers: escaping output _and_ a Content Security Policy, CSRF tokens _and_ `SameSite` cookies.
- What session fixation is, and how `session.use_strict_mode` plus `session_regenerate_id()` prevent it.
- Behind a reverse proxy such as Render, PHP only sees plain HTTP. The original scheme arrives in the `X-Forwarded-Proto` header.
- Languages keep moving: PHP 8.5 deprecates `$http_response_header` in favour of `http_get_last_response_headers()`.
- How to pick chart colours that stay distinguishable for colour-blind users, and check them with a validator instead of by eye.
- How to run a modern PHP next to XAMPP on Windows: `php.ini`, extensions and CA certificates.
- A canvas needs its pixel buffer scaled by `devicePixelRatio`, or charts look blurry on high-DPI screens.
- Tooltips on a canvas mean doing your own hit-testing: working out which bar, slice or point is under the pointer.
- GitHub allows 60 unauthenticated requests per hour per IP address, shared with everything else on the same network, so caching and counting requests matter.
- A file's extension is just part of its name. `fileinfo` checks what the bytes really are, and `is_uploaded_file()` proves PHP received the file in this request.
- When a request is bigger than `post_max_size`, PHP silently empties `$_POST` and `$_FILES`, so "file too big" has to be detected from `CONTENT_LENGTH`.
- Post/Redirect/Get: answering a form POST with a redirect stops the browser from re-sending it on refresh.
- Excel saves "CSV" in Windows-1252 and "CSV UTF-8" with a byte order mark, so a parser has to handle both.
- "1,234" and "1,23" look alike, but only correctly grouped commas are thousands separators.
- CSV formula injection: a cell like `=HYPERLINK(...)` in an exported file runs as a formula when someone opens it in a spreadsheet, so exports have to defuse it.
- A download is just a response with the right headers: `Content-Type: text/csv` and `Content-Disposition: attachment` make the browser save it, and `php://output` streams it without a temporary file.
- Excel only reads a CSV as UTF-8 when it starts with a byte order mark.

## License

[MIT](LICENSE) © 2026 Divyansh Garg

`public/js/vendor/gsap.min.js` is GSAP by GreenSock, included unchanged under its own [Standard "No Charge" License](https://gsap.com/standard-license), not the MIT licence. The Hanken Grotesk font files in `public/fonts/` are under the SIL Open Font License ([OFL.txt](public/fonts/OFL.txt)).
