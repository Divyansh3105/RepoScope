<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// Analyze: POST uploads a CSV into the session and redirects; GET shows stats and a chart.

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // over post_max_size: PHP drops the whole body, csrf token included
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
        // TODO: the whole table (up to ~2 MB) sits in the session; cap it if this ever runs on a shared host
        $_SESSION['analyze'] = [
            'name'  => mb_substr(basename((string) $_FILES['csv']['name']), 0, 100),
            'table' => $parsed['table'],
            'notes' => $parsed['notes'],
        ];
        $_SESSION['analyze_fresh'] = true; // animate the chart on the next view

        // redirect so a refresh doesn't re-submit the upload
        header('Location: analyze.php', true, 303);
        exit;
    }
    http_response_code(400);
}

$dataset = $_SESSION['analyze'] ?? null;
$fresh = isset($_SESSION['analyze_fresh']);
unset($_SESSION['analyze_fresh']);

session_write_close();

if ($dataset !== null) {
    $table = $dataset['table'];
    $headers = $table['headers'];
    $rowCount = count($table['rows']);
    $types = column_types($table);
    $numberCols = array_keys($types, 'number');
    $textCols = array_keys($types, 'text');

    $numberSummary = number_summary($table, $numberCols);
    $textSummary = text_summary($table, $textCols);

    // number columns worth summing by default (skip ID-like ones: all unique ints)
    $measures = array_values(array_filter($numberCols, function (int $col) use ($table): bool {
        $numbers = column_numbers($table, $col);
        return count(array_filter($numbers, 'is_int')) < count($numbers) || count(array_unique($numbers)) < count($numbers);
    }));
    $groupable = []; // text col => distinct values, if 2+
    foreach ($textSummary['rows'] as $i => $row) {
        if ($row[3] >= 2) { // Unique
            $groupable[$textCols[$i]] = $row[3];
        }
    }

    $column = fn(mixed $value, array $allowed, int $default): int =>
    is_string($value) && ctype_digit($value) && in_array((int) $value, $allowed, true) ? (int) $value : $default;

    // defaults: group by the text column with the fewest distinct values, sum the first non-ID number column
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
        'sum'   => "{$headers[$y]} added up for each value of {$headers[$x]}; cells that aren't numbers are skipped.",
        'avg'   => "The mean of {$headers[$y]} for each value of {$headers[$x]}; cells that aren't numbers are skipped.",
    };
    if ($type === 'line') {
        // natural sort so "9" comes before "10"
        array_multisort($chart['labels'], SORT_NATURAL, $chart['values']);
        $chartNote .= " In {$headers[$x]} order.";
    } else {
        array_multisort($chart['values'], SORT_DESC, $chart['labels']);
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

function option(string|int $value, string $label, string|int $current): string
{
    return '<option value="' . e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . e($label) . '</option>';
}

render_header($dataset !== null ? $dataset['name'] . ' · Analyze' : 'Analyze', 'analyze');
?>
<section class="page-head">
    <div>
        <h1>Analyze</h1>
        <p>Upload any CSV file to see which columns hold numbers or text, their statistics, and a chart of the columns you pick.
            No file handy? Try the <a href="samples/sales.csv" download>sample sales data</a>.</p>
    </div>
    <form class="search" action="analyze.php" method="post" enctype="multipart/form-data" data-pending="Reading...">
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
            <circle cx="10" cy="10" r="8" />
            <path d="M10 6v5M10 14h.01" />
        </svg>
        <p><strong>Error:</strong> <?= e($error) ?></p>
    </div>
<?php endif; ?>

<?php if ($dataset !== null): ?>
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

    <section class="chart-grid" aria-label="Chart"<?= $fresh ? ' data-animate' : '' ?>>
        <?php render_chart($type, $chart, [$headers[$x], $measure], 'chart-wide', note: $chartNote); ?>
    </section>

    <?php if ($numberCols !== []): ?>
        <section class="panel">
            <div class="panel-head">
                <h2>Number columns <span class="muted">(<?= count($numberCols) ?>)</span></h2>
                <?= export_link('table=analyze-numbers', 'number columns') ?>
            </div>
            <p class="chart-note">Count is how many cells hold a number. Skipped cells were empty or not a number.</p>
            <?php render_table($numberSummary); ?>
        </section>
    <?php endif; ?>

    <?php if ($textCols !== []): ?>
        <section class="panel">
            <div class="panel-head">
                <h2>Text columns <span class="muted">(<?= count($textCols) ?>)</span></h2>
                <?= export_link('table=analyze-text', 'text columns') ?>
            </div>
            <p class="chart-note">Count is how many cells are filled in. The most common values show how often each appears.</p>
            <?php render_table($textSummary, [4 => $wrap]); ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-head">
            <h2>Data <span class="muted">(<?= $rowCount > $previewRows ? "first {$previewRows} of " . number_format($rowCount) . ' rows' : $rowCount . ($rowCount === 1 ? ' row' : ' rows') ?>)</span></h2>
            <?= export_link('table=analyze', 'all rows') ?>
        </div>
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
