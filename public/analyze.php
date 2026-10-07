<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

/*
 * Analyze mode. Two requests:
 *   POST (the upload form)   check the file, parse it, keep its table in the session,
 *                            then redirect to a plain GET of this page.
 *   GET  (everything else)   show the table from the session: column types, statistics,
 *                            a chart of the columns picked in the chart form, and a preview.
 */

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Bigger than php.ini's post_max_size: PHP throws the whole request away, token included.
        $error = CSV_TOO_BIG;
    } elseif (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'This form expired. Please choose the file and upload it again.';
    } else {
        $error = csv_upload_error($_FILES['csv'] ?? null);
        if ($error === '') {
            $parsed = csv_parse((string) file_get_contents($_FILES['csv']['tmp_name']));
            $error = $parsed['error'] ?? '';
        }
    }

    if ($error === '') {
        // ponytail: the whole table (up to ~2 MB) lives in the session and is read on every page
        // view. Fine for one visitor's file; a shared server would want a size cap per session.
        $_SESSION['analyze'] = [
            'name'  => mb_substr(basename((string) $_FILES['csv']['name']), 0, 100), // shown escaped, never used as a path
            'table' => $parsed['table'],
            'notes' => $parsed['notes'],
        ];
        $_SESSION['analyze_fresh'] = true; // the next view draws its chart in

        // Post/Redirect/Get: the browser lands on a normal GET page, so a refresh
        // doesn't ask to send the file again, and the chart form's links work.
        header('Location: analyze.php', true, 303);
        exit;
    }
    http_response_code(400);
}

$dataset = $_SESSION['analyze'] ?? null;
$fresh = isset($_SESSION['analyze_fresh']);
unset($_SESSION['analyze_fresh']);

// Everything that touches the session is done: release its lock before the work and the page.
session_write_close();

if ($dataset !== null) {
    $table = $dataset['table'];
    $headers = $table['headers'];
    $rowCount = count($table['rows']);
    $types = column_types($table);
    $numberCols = array_keys($types, 'number'); // positions of the number columns
    $textCols = array_keys($types, 'text');

    // The statistics are tables too (the one table shape), one row per column of the file.
    $numberSummary = ['headers' => ['Column', 'Count', 'Skipped', 'Sum', 'Min', 'Max', 'Mean', 'Median'], 'rows' => []];
    $measures = []; // number columns worth adding up: not IDs
    foreach ($numberCols as $col) {
        $numbers = column_numbers($table, $col);
        // An ID column (whole numbers, all different) is never the default thing to add up.
        if (count(array_filter($numbers, 'is_int')) < count($numbers) || count(array_unique($numbers)) < count($numbers)) {
            $measures[] = $col;
        }
        $stats = number_stats($numbers);
        $numberSummary['rows'][] = [$headers[$col], $stats['count'], $rowCount - $stats['count'], $stats['sum'],
            $stats['min'], $stats['max'], $stats['mean'], $stats['median']];
    }
    $textSummary = ['headers' => ['Column', 'Count', 'Empty', 'Unique', 'Most common (up to 10)'], 'rows' => []];
    $groupable = []; // text column => how many different values it has, when that is 2 or more
    foreach ($textCols as $col) {
        $counts = chart_count($table, $col, '');
        if (count($counts['labels']) >= 2) {
            $groupable[$col] = count($counts['labels']);
        }
        $filled = array_sum($counts['values']);
        $top = array_map(fn(string $value, int $n): string => "{$value} ({$n})",
            array_slice($counts['labels'], 0, 10), array_slice($counts['values'], 0, 10));
        $textSummary['rows'][] = [$headers[$col], $filled, $rowCount - $filled, count($counts['labels']), implode(' · ', $top)];
    }

    // The chart form. Every value is checked against a fixed list before it is used.
    $column = fn(mixed $value, array $allowed, int $default): int =>
        is_string($value) && ctype_digit($value) && in_array((int) $value, $allowed, true) ? (int) $value : $default;

    // With no choices made yet, group by the text column with the fewest different values
    // (Region rather than Customer name) and add up the first number column that isn't an ID.
    asort($groupable);
    $type = pick($_GET['type'] ?? null, ['bar', 'line', 'pie'], 'bar');
    $x = $column($_GET['x'] ?? null, array_keys($headers), array_key_first($groupable) ?? $textCols[0] ?? 0);
    $calc = $numberCols === [] ? 'count' : pick($_GET['calc'] ?? null, ['count', 'sum', 'avg'], 'sum');
    $y = $column($_GET['y'] ?? null, $numberCols, array_values(array_diff($measures, [$x]))[0] ?? $numberCols[0] ?? 0);

    $measure = match ($calc) {
        'count' => 'Rows',
        'sum'   => 'Sum of ' . $headers[$y],
        'avg'   => 'Average ' . $headers[$y],
    };
    $chart = chart_group($table, $x, $y, $calc, $measure . ' by ' . $headers[$x]);
    $groups = count($chart['labels']);

    $chartNote = match ($calc) {
        'count' => "How many rows hold each value of {$headers[$x]}.",
        'sum'   => "{$headers[$y]} added up for each value of {$headers[$x]}; cells that aren’t numbers are skipped.",
        'avg'   => "The mean of {$headers[$y]} for each value of {$headers[$x]}; cells that aren’t numbers are skipped.",
    };
    if ($type === 'line') {
        // A route runs in order along the x axis. Natural order puts "9" before "10".
        array_multisort($chart['labels'], SORT_NATURAL, $chart['values']);
        $chartNote .= " In {$headers[$x]} order.";
    } else {
        array_multisort($chart['values'], SORT_DESC, $chart['labels']); // largest first
        if ($type === 'bar' && $groups > 20) {
            $chart['labels'] = array_slice($chart['labels'], 0, 20);
            $chart['values'] = array_slice($chart['values'], 0, 20);
            $chartNote .= " The 20 largest of {$groups} groups.";
        } elseif ($type === 'pie' && $groups > 8) {
            $chartNote .= ' Beyond the 8 largest, groups are combined as Other.';
        }
    }

    $previewRows = 100;
    $isNumber = array_map(fn(string $type): bool => $type === 'number', $types);
    $wrap = fn(mixed $text): string => '<span class="cell-wrap">' . e((string) $text) . '</span>';
}

/** One <option>, marked selected when it is the current value. */
function option(string|int $value, string $label, string|int $current): string
{
    return '<option value="' . e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . e($label) . '</option>';
}

render_header($dataset !== null ? $dataset['name'] . ' · Analyze' : 'Analyze', 'analyze');
?>
<section class="page-head">
    <div>
        <h1>Analyze</h1>
        <p>Upload any CSV file to see which columns hold numbers or text, their statistics, and a chart of the columns you pick.</p>
    </div>
    <!-- multipart/form-data is the encoding that can carry a file. POST, because an upload changes the session. -->
    <form class="search" action="analyze.php" method="post" enctype="multipart/form-data" data-pending="Reading…">
        <?= csrf_field() ?>
        <label for="csv">CSV file <span class="muted">(up to <?= UPLOAD_MAX_BYTES / 1048576 ?> MB)</span></label>
        <div class="field-row">
            <input id="csv" name="csv" type="file" accept=".csv,text/csv" required
                   <?= $error !== '' ? 'aria-invalid="true" aria-describedby="upload-error"' : '' ?>>
            <button class="btn" type="submit"><span class="t-text-swap">Analyze</span></button>
        </div>
    </form>
</section>

<?php if ($error !== ''): ?>
    <div class="alert" id="upload-error" role="alert">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="10" cy="10" r="8"/><path d="M10 6v5M10 14h.01"/>
        </svg>
        <p><strong>Error:</strong> <?= e($error) ?></p>
    </div>
<?php endif; ?>

<?php if ($dataset !== null): ?>
    <!-- What was read and what was changed or left out (honest numbers). -->
    <ul class="status" aria-label="About this file">
        <li><?= e($dataset['name']) ?></li>
        <li><?= number_format($rowCount) ?> <?= $rowCount === 1 ? 'row' : 'rows' ?>, <?= count($headers) ?> <?= count($headers) === 1 ? 'column' : 'columns' ?></li>
        <li><?= count($numberCols) ?> number, <?= count($textCols) ?> text</li>
        <?php foreach ($dataset['notes'] as $note): ?>
            <li><?= e($note) ?></li>
        <?php endforeach; ?>
    </ul>

    <section class="panel" aria-labelledby="builder-title">
        <h2 id="builder-title">Chart</h2>
        <!-- GET: picking a chart changes nothing on the server, so the result can be bookmarked. -->
        <form class="builder" action="analyze.php" method="get">
            <div>
                <label for="type">Type</label>
                <select id="type" name="type">
                    <?= option('bar', 'Bar', $type) . option('line', 'Line', $type) . option('pie', 'Pie', $type) ?>
                </select>
            </div>
            <div>
                <label for="x">Group by</label>
                <select id="x" name="x">
                    <?php foreach ($headers as $i => $header): ?><?= option($i, $header, $x) ?><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="calc">Calculate</label>
                <select id="calc" name="calc">
                    <?= option('count', 'Count of rows', $calc) ?>
                    <?php if ($numberCols !== []): ?>
                        <?= option('sum', 'Sum', $calc) . option('avg', 'Average', $calc) ?>
                    <?php endif; ?>
                </select>
            </div>
            <?php if ($numberCols !== []): ?>
                <div>
                    <label for="y">Of number column</label>
                    <select id="y" name="y">
                        <?php foreach ($numberCols as $i): ?><?= option($i, $headers[$i], $y) ?><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <button class="btn" type="submit">Draw chart</button>
        </form>
    </section>

    <!-- data-animate: the chart draws itself in only on the first view after an upload. -->
    <section class="chart-grid" aria-label="Chart"<?= $fresh ? ' data-animate' : '' ?>>
        <?php render_chart($type, $chart, [$headers[$x], $measure], 'chart-wide', note: $chartNote); ?>
    </section>

    <?php if ($numberCols !== []): ?>
        <section class="panel">
            <h2>Number columns <span class="muted">(<?= count($numberCols) ?>)</span></h2>
            <p class="chart-note">Count is how many cells hold a number. Skipped cells were empty or not a number.</p>
            <?php render_table($numberSummary); ?>
        </section>
    <?php endif; ?>

    <?php if ($textCols !== []): ?>
        <section class="panel">
            <h2>Text columns <span class="muted">(<?= count($textCols) ?>)</span></h2>
            <p class="chart-note">Count is how many cells are filled in. The most common values show how often each appears.</p>
            <?php render_table($textSummary, [4 => $wrap]); ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Data <span class="muted">(<?= $rowCount > $previewRows ? "first {$previewRows} of " . number_format($rowCount) . ' rows' : $rowCount . ($rowCount === 1 ? ' row' : ' rows') ?>)</span></h2>
        <?php render_table(['headers' => $headers, 'rows' => array_slice($table['rows'], 0, $previewRows)], numeric: $isNumber); ?>
    </section>
<?php elseif ($error === ''): ?>
    <section class="panel">
        <h2>Start with a CSV file</h2>
        <ul class="rules">
            <li>Up to <?= UPLOAD_MAX_BYTES / 1048576 ?> MB, <?= number_format(CSV_MAX_ROWS) ?> rows and <?= CSV_MAX_COLS ?> columns, separated by commas.</li>
            <li>The first row is used as column names, unless it holds a number.</li>
            <li>A column counts as numbers when at least <?= NUMERIC_THRESHOLD * 100 ?>% of its filled cells are numbers.</li>
            <li>The file itself is never stored. Its table stays in your session until the session ends.</li>
        </ul>
    </section>
<?php endif; ?>
<?php
render_footer();
