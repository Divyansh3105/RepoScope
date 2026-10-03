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

/** Prints the top of every page: <head>, the site header and the navigation. */
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
    <link rel="stylesheet" href="css/style.css">
    <!-- defer: download now, run after the HTML is parsed, so the script can find every <canvas>. -->
    <script src="js/charts.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="container header-inner">
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

/** Formats one table cell: numbers get thousands separators, everything else is escaped text. */
function format_cell(mixed $value): string
{
    return match (true) {
        is_int($value)   => number_format($value),
        is_float($value) => rtrim(rtrim(number_format($value, 2), '0'), '.'), // 2.50 → "2.5", 3.00 → "3"
        default          => e((string) $value),
    };
}

/** Prints a table-shaped array (['headers' => [...], 'rows' => [[...], ...]]) as an HTML table. */
function render_table(array $table): void
{
    if ($table['rows'] === []) {
        echo '<p class="muted">No rows to show.</p>';
        return;
    }
    // Number columns are right-aligned, so digits line up. The first row decides.
    $numeric = array_map(fn(mixed $cell): bool => is_int($cell) || is_float($cell), $table['rows'][0]);
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
                        <td<?= ($numeric[$i] ?? false) ? ' class="num"' : '' ?>><?= format_cell($cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
    <?php
}

/**
 * Prints one chart card: a heading, the <canvas> js/charts.js draws on, the chart data
 * as JSON, and the same data as an HTML table (for screen readers, and exact numbers).
 *
 * $type    'bar', 'line' or 'pie'
 * $chart   ['title' => ..., 'labels' => [...], 'values' => [...]] from a function in stats.php
 * $columns the data table's two column names, e.g. ['Language', 'Repositories']
 */
function render_chart(string $type, array $chart, array $columns, string $extraClass = ''): void
{
    static $count = 0;          // static: keeps its value between calls, so every chart gets a new id
    $id = 'chart-' . ++$count;  // ties the canvas to its JSON block and its heading

    // SECURITY: these flags write < > & ' " as <-style escapes, so a label such as
    // "</script><script>alert(1)</script>" (a repo name, a CSV cell) can't end the <script> block early.
    $json = json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $hasData = array_filter($chart['values']) !== []; // all zeros counts as nothing to draw
    $rows = array_map(null, $chart['labels'], $chart['values']); // array_map(null, ...) zips: [[label, value], ...]
    ?>
<figure class="card chart <?= e($extraClass) ?>">
    <h2 id="<?= $id ?>-title"><?= e($chart['title']) ?></h2>
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
            <summary>Show the data as a table</summary>
            <?php render_table(['headers' => $columns, 'rows' => $rows]); ?>
        </details>
    <?php endif; ?>
</figure>
    <?php
}

/** Prints the bottom of every page. */
function render_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <div class="container">RepoScope · GitHub &amp; CSV analytics built with plain PHP and HTML5 Canvas</div>
</footer>
</body>
</html>
    <?php
}
