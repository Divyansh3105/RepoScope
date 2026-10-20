<?php

declare(strict_types=1);

// Shared page markup. Pages call render_header(), print their content, then render_footer().

// Absolute URL for the current host, for canonical and Open Graph tags. The Host header is
// client-controlled, so anything that isn't a plain host[:port] falls back to localhost.
function site_url(string $path = '/'): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return (is_https() ? 'https://' : 'http://') . $host . $path;
}

// $meta keys: description (string), query (canonical query string), noindex (bool)
function render_header(string $title, string $active = '', array $meta = []): void
{
    $root = (string) ($meta['root'] ?? ''); // '/' for pages served from any URL depth (the 404)
    $noindex = !empty($meta['noindex']);
    $descriptions = [
        'home'    => 'Look up any GitHub developer, compare a group, or analyze a CSV file. RepoScope turns them into statistics and interactive charts.',
        'profile' => 'Languages, most-starred repositories and repositories per year for any public GitHub user.',
        'compare' => 'Upload a CSV of up to 15 GitHub usernames and get a leaderboard with charts for stars, followers, repositories and languages.',
        'analyze' => 'Upload a CSV file to detect column types, get summary statistics and chart any grouping as a bar, line or pie chart.',
    ];
    $description = (string) ($meta['description'] ?? $descriptions[$active] ?? $descriptions['home']);
    $fullTitle = $active === 'home' ? 'RepoScope · GitHub profiles and CSV files as charts' : $title . ' · RepoScope';
    $script = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    $canonicalPath = in_array($script, ['', 'index.php'], true) ? '/' : '/' . $script;
    $canonical = site_url($canonicalPath . (($meta['query'] ?? '') !== '' ? '?' . $meta['query'] : ''));
    $image = site_url('/og-image.png');
    $imageAlt = 'RepoScope: GitHub profiles and CSV files turned into statistics and charts';
    $nav = [
        'home'    => ['index.php', 'Home'],
        'profile' => ['profile.php', 'Profile'],
        'compare' => ['compare.php', 'Compare'],
        'analyze' => ['analyze.php', 'Analyze'],
    ];
    // the explicit favicon link stops `php -S` from answering /favicon.ico with index.php
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($fullTitle) ?></title>
        <meta name="description" content="<?= e($description) ?>">
        <meta name="robots" content="<?= $noindex ? 'noindex, nofollow' : 'index, follow' ?>">
        <meta name="theme-color" content="#000000">
        <?php if (!$noindex): ?>
            <link rel="canonical" href="<?= e($canonical) ?>">
        <?php endif; ?>
        <meta property="og:site_name" content="RepoScope">
        <meta property="og:type" content="website">
        <meta property="og:locale" content="en_US">
        <meta property="og:title" content="<?= e($fullTitle) ?>">
        <meta property="og:description" content="<?= e($description) ?>">
        <meta property="og:url" content="<?= e($canonical) ?>">
        <meta property="og:image" content="<?= e($image) ?>">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="<?= e($imageAlt) ?>">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="<?= e($fullTitle) ?>">
        <meta name="twitter:description" content="<?= e($description) ?>">
        <meta name="twitter:image" content="<?= e($image) ?>">
        <meta name="twitter:image:alt" content="<?= e($imageAlt) ?>">
        <link rel="icon" href="<?= e($root) ?>favicon.svg" type="image/svg+xml">
        <link rel="preload" href="<?= e($root) ?>fonts/hanken-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="stylesheet" href="<?= e($root) ?>css/style.css">
        <script src="<?= e($root) ?>js/vendor/gsap.min.js" defer></script>
        <script src="<?= e($root) ?>js/charts.js" defer></script>
        <script src="<?= e($root) ?>js/ui.js" defer></script>
    </head>

    <body>
        <a class="skip-link" href="#main">Skip to content</a>
        <header class="sign-band">
            <div class="container band-inner">
                <a class="brand" href="<?= e($root) ?>index.php">
                    <svg class="brand-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="10.5" cy="10.5" r="7" />
                        <path d="M15.5 15.5 21 21M7.5 13v-1.5M10.5 13V8M13.5 13v-3" />
                    </svg>
                    RepoScope
                </a>
                <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">
                    Menu
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" aria-hidden="true">
                        <path class="bar-top" d="M4 7h16" />
                        <path class="bar-mid" d="M4 12h16" />
                        <path class="bar-bot" d="M4 17h16" />
                    </svg>
                </button>
                <nav id="site-nav" aria-label="Main">
                    <ul class="nav">
                        <?php foreach ($nav as $key => [$href, $label]): ?>
                            <li><a href="<?= e($root . $href) ?>" <?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </div>
        </header>
        <main id="main" class="container">
        <?php
    }

    // Two-letter bullet code: JavaScript -> JS, Jupyter Notebook -> JN, HTML -> HT, Python -> Py.
    // lineCode() in charts.js does the same thing.
    function line_code(string $label): string
    {
        $label = trim($label);
        $words = preg_split('/[\s_-]+/', $label, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        }
        if (preg_match('/^[A-Z0-9#+]+$/', $label)) { // HTML, CSS, C#, C++
            return mb_substr($label, 0, 2);
        }
        $capitals = preg_replace('/[^A-Z]/', '', $label);
        if (strlen($capitals) >= 2) { // JavaScript, TypeScript
            return substr($capitals, 0, 2);
        }
        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_strtolower(mb_substr($label, 1, 1));
    }

    // Coloured bullet for a category. Slots 0-7 match the chart colours, 8+ is "Other".
    function line_bullet(string $label, int $slot, string $size = ''): string
    {
        $classes = 'bullet ' . ($slot < 8 ? 'line-' . ($slot + 1) : 'line-other') . ($size !== '' ? ' ' . $size : '');
        return '<span class="' . e($classes) . '" aria-hidden="true">' . e(line_code($label)) . '</span>';
    }

    function format_cell(mixed $value): string
    {
        if (is_float($value)) {
            // 2 decimals, but 3 significant digits for small values so 0.0035 doesn't show as 0
            $decimals = $value == 0 || abs($value) >= 1 ? 2 : min(8, 2 - (int) floor(log10(abs($value))));
            return rtrim(rtrim(number_format($value, $decimals), '0'), '.');
        }
        return is_int($value) ? number_format($value) : e((string) $value);
    }

    // $formatters: column index => fn($cell) returning (escaped) HTML for that cell.
    // $numeric: which columns to right-align; defaults to the types in the first row.
    function render_table(array $table, array $formatters = [], ?array $numeric = null): void
    {
        if ($table['rows'] === []) {
            echo '<p class="muted">No rows to show.</p>';
            return;
        }
        $numeric ??= array_map(fn(mixed $cell): bool => is_int($cell) || is_float($cell), $table['rows'][0]);
        ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($table['headers'] as $i => $header): ?>
                                <th scope="col" <?= ($numeric[$i] ?? false) ? ' class="num"' : '' ?>><?= e($header) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($table['rows'] as $row): ?>
                            <tr>
                                <?php foreach ($row as $i => $cell): ?>
                                    <td<?= ($numeric[$i] ?? false) ? ' class="num"' : '' ?>><?= isset($formatters[$i]) ? $formatters[$i]($cell) : format_cell($cell) ?></td>
                                    <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php
    }

    // Chart panel: title, canvas, the data as JSON for charts.js, and a data table fallback.
    // $type is bar, hbar, line or pie; $columns names the two columns of the data table.
    function render_chart(string $type, array $chart, array $columns, string $extraClass = '', string $note = ''): void
    {
        static $count = 0;
        $id = 'chart-' . ++$count;

        // JSON_HEX_* so a label like "</script>" can't close the script tag
        $json = json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $hasData = array_filter($chart['values']) !== [];
        $rows = array_map(null, $chart['labels'], $chart['values']); // zip into [label, value] pairs
        ?>
            <figure class="panel chart <?= e($extraClass) ?>">
                <h2 id="<?= $id ?>-title"><?= e($chart['title']) ?></h2>
                <?php if ($note !== ''): ?>
                    <p class="chart-note"><?= e($note) ?></p>
                <?php endif; ?>
                <?php if (!$hasData): ?>
                    <p class="muted">Nothing to chart yet.</p>
                <?php else: ?>
                    <div class="chart-body">
                        <div class="chart-canvas">
                            <canvas data-chart="<?= e($type) ?>" data-source="<?= $id ?>" role="img" aria-labelledby="<?= $id ?>-title"></canvas>
                        </div>
                    </div>
                    <script type="application/json" id="<?= $id ?>">
                        <?= $json ?>
                    </script>
                    <details class="chart-data">
                        <summary>
                            <svg class="chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 6.5L8 10.5L12 6.5" />
                            </svg>
                            Data table <span class="muted">(<?= count($rows) ?> <?= count($rows) === 1 ? 'row' : 'rows' ?>)</span>
                        </summary>
                        <?php render_table(['headers' => $columns, 'rows' => $rows]); ?>
                    </details>
                <?php endif; ?>
            </figure>
        <?php
    }

    // $kind is 'error' or 'success'. $html must already be escaped (it may hold links).
    function alert_html(string $kind, string $html, string $id = ''): string
    {
        $icon = $kind === 'success'
            ? '<circle cx="10" cy="10" r="8" /><path d="M6.5 10.5l2.5 2.5 4.5-5" />'
            : '<circle cx="10" cy="10" r="8" /><path d="M10 6v5M10 14h.01" />';
        return '<div class="alert alert-' . e($kind) . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '')
            . ' role="' . ($kind === 'success' ? 'status' : 'alert') . '">'
            . '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">'
            . $icon . '</svg><p>' . $html . '</p></div>';
    }

    // aria-label tells screen readers which table each "Export CSV" link is for
    function export_link(string $query, string $what): string
    {
        return '<a class="export" href="export.php?' . e($query) . '" aria-label="Export CSV, ' . e($what) . '">Export CSV</a>';
    }

    // "2026", or "2026-2027" once the year has moved on
    function copyright_years(): string
    {
        $now = (int) date('Y');
        return $now > COPYRIGHT_FROM ? COPYRIGHT_FROM . '-' . $now : (string) COPYRIGHT_FROM;
    }

    function render_footer(): void
    {
        ?>
        </main>
        <footer class="site-footer">
            <div class="container footer-inner">
                <p>&copy; <?= e(copyright_years()) ?> Divyansh Garg. GitHub and CSV analytics in plain PHP and HTML5 Canvas.</p>
                <ul class="footer-links" aria-label="Project">
                    <li><a href="<?= e(REPO_URL) ?>" rel="noopener">Source on GitHub</a></li>
                    <li><a href="<?= e(REPO_URL) ?>/blob/main/LICENSE" rel="noopener">MIT License</a></li>
                </ul>
            </div>
        </footer>
    </body>

    </html>
<?php
    }
