<?php
declare(strict_types=1);

/*
 * Page layout helpers. A page looks like:
 *
 *     render_header('Profile', 'profile');
 *     ... the page's own HTML ...
 *     render_footer();
 *
 * Inside these functions we close the PHP tag (?>) to write plain HTML and reopen
 * it (<?php) when we need PHP again. The HTML is printed when the function runs.
 */

/** Prints the top of every page: <head>, the sign band (brand and navigation). */
function render_header(string $title, string $active = ''): void
{
    // nav key => [link, label]. Links are relative, so the site also works from a sub-folder.
    $nav = [
        'home'    => ['index.php', 'Home'],
        'profile' => ['profile.php', 'Profile'],
        'compare' => ['compare.php', 'Compare'],
        'analyze' => ['analyze.php', 'Analyze'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · RepoScope</title>
    <!-- Declaring an icon stops browsers requesting /favicon.ico, which `php -S` would answer by running index.php. -->
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <!-- Start downloading the main font file right away, instead of waiting for the CSS to ask for it. -->
    <link rel="preload" href="fonts/hanken-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="css/style.css">
    <!-- defer: download now, run in this order after the HTML is parsed, so the scripts can find the page. -->
    <script src="js/vendor/gsap.min.js" defer></script>
    <script src="js/charts.js" defer></script>
    <script src="js/ui.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="sign-band">
    <div class="container band-inner">
        <a class="brand" href="index.php">
            <svg class="brand-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="10.5" cy="10.5" r="7"/>
                <path d="M15.5 15.5 21 21M7.5 13v-1.5M10.5 13V8M13.5 13v-3"/>
            </svg>
            RepoScope
        </a>
        <nav aria-label="Main">
            <ul class="nav">
                <?php foreach ($nav as $key => [$href, $label]): ?>
                    <li><a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</header>
<main id="main" class="container">
    <?php
}

/**
 * Two-letter code for a line bullet: "JavaScript" → "JS", "Jupyter Notebook" → "JN",
 * "HTML" → "HT", "Python" → "Py". js/charts.js uses the same rule for the pie legend.
 */
function line_code(string $label): string
{
    $label = trim($label);
    $words = preg_split('/[\s_-]+/', $label, -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) >= 2) {
        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
    }
    if (preg_match('/^[A-Z0-9#+]+$/', $label)) { // all capitals or symbols: HTML, CSS, C#, C++
        return mb_substr($label, 0, 2);
    }
    $capitals = preg_replace('/[^A-Z]/', '', $label);
    if (strlen($capitals) >= 2) {                 // CamelCase: JavaScript, TypeScript
        return substr($capitals, 0, 2);
    }
    return mb_strtoupper(mb_substr($label, 0, 1)) . mb_strtolower(mb_substr($label, 1, 1));
}

/**
 * A transit-style line bullet: a coloured disc carrying a two-letter code.
 * $slot 0-7 picks the line colour (the same order the charts use); 8 and up is "Other".
 * It is decorative (the name is always printed next to it), so screen readers skip it.
 */
function line_bullet(string $label, int $slot, string $size = ''): string
{
    $classes = 'bullet ' . ($slot < 8 ? 'line-' . ($slot + 1) : 'line-other') . ($size !== '' ? ' ' . $size : '');
    return '<span class="' . e($classes) . '" aria-hidden="true">' . e(line_code($label)) . '</span>';
}

/** Formats one table cell: numbers get thousands separators, everything else is escaped text. */
function format_cell(mixed $value): string
{
    if (is_float($value)) {
        // Two decimals, but three significant digits below 1, so 0.0035 isn't shown as "0".
        $decimals = $value == 0 || abs($value) >= 1 ? 2 : min(8, 2 - (int) floor(log10(abs($value))));
        return rtrim(rtrim(number_format($value, $decimals), '0'), '.'); // 2.50 → "2.5", 3.00 → "3"
    }
    return is_int($value) ? number_format($value) : e((string) $value);
}

/**
 * Prints a table-shaped array (['headers' => [...], 'rows' => [[...], ...]]) as an HTML table.
 * $formatters can map a column number to a function that returns that column's cell HTML
 * (it must escape what it prints), for example to put a line bullet before a language.
 * $numeric lists true/false per column; leave it out and the first row's cell types decide.
 */
function render_table(array $table, array $formatters = [], ?array $numeric = null): void
{
    if ($table['rows'] === []) {
        echo '<p class="muted">No rows to show.</p>';
        return;
    }
    // Number columns are right-aligned, so digits line up.
    $numeric ??= array_map(fn(mixed $cell): bool => is_int($cell) || is_float($cell), $table['rows'][0]);
    ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <?php foreach ($table['headers'] as $i => $header): ?>
                    <th scope="col"<?= ($numeric[$i] ?? false) ? ' class="num"' : '' ?>><?= e($header) ?></th>
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

/**
 * Prints one chart panel: a heading, the <canvas> js/charts.js draws on, the chart data
 * as JSON, and the same data as an HTML table (for screen readers, and exact numbers).
 *
 * $type    'bar', 'hbar' (ranked, horizontal bars), 'line' or 'pie'
 * $chart   ['title' => ..., 'labels' => [...], 'values' => [...]] from a function in stats.php
 * $columns the data table's two column names, e.g. ['Language', 'Repositories']
 * $note    one line under the title saying exactly what was counted (optional)
 */
function render_chart(string $type, array $chart, array $columns, string $extraClass = '', string $note = ''): void
{
    static $count = 0;          // static: keeps its value between calls, so every chart gets a new id
    $id = 'chart-' . ++$count;  // ties the canvas to its JSON block and its heading

    // SECURITY: these flags write < > & ' " as <-style escapes, so a label such as
    // "</script><script>alert(1)</script>" (a repo name, a CSV cell) can't end the <script> block early.
    $json = json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $hasData = array_filter($chart['values']) !== []; // all zeros counts as nothing to draw
    $rows = array_map(null, $chart['labels'], $chart['values']); // array_map(null, ...) zips: [[label, value], ...]
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
        <script type="application/json" id="<?= $id ?>"><?= $json ?></script>
        <details class="chart-data">
            <summary>
                <svg class="chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6.5L8 10.5L12 6.5"/></svg>
                Data table <span class="muted">(<?= count($rows) ?> <?= count($rows) === 1 ? 'row' : 'rows' ?>)</span>
            </summary>
            <?php render_table(['headers' => $columns, 'rows' => $rows]); ?>
        </details>
    <?php endif; ?>
</figure>
    <?php
}

/**
 * An "Export CSV" link to export.php for one result table. $what names the table for screen
 * readers, which otherwise hear several identical "Export CSV" links on one page.
 */
function export_link(string $query, string $what): string
{
    return '<a class="export" href="export.php?' . e($query) . '" aria-label="Export CSV, ' . e($what) . '">Export CSV</a>';
}

/** Prints the bottom of every page. */
function render_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <div class="container">RepoScope · GitHub and CSV analytics in plain PHP and HTML5 Canvas</div>
</footer>
</body>
</html>
    <?php
}
