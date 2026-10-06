<?php
declare(strict_types=1);

/*
 * Functions that work on the app's one table shape:
 *
 *     ['headers' => ['Repository', 'Stars'], 'rows' => [['repo-a', 120], ['repo-b', 45]]]
 *
 * Chart-data builders turn a table into what js/charts.js draws:
 *
 *     ['title' => 'Stars', 'labels' => ['repo-a', 'repo-b'], 'values' => [120, 45]]
 *
 * Every function here is pure: it only reads its arguments and returns a new value.
 */

/** Counts how often each non-empty value appears in column $col, most common first. */
function chart_count(array $table, int $col, string $title): array
{
    $counts = [];
    foreach ($table['rows'] as $row) {
        $value = (string) ($row[$col] ?? '');
        if ($value !== '') {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
    }
    arsort($counts); // biggest count first (ties keep their original order)

    return [
        'title'  => $title,
        // PHP turns array keys like "2024" into integers; strval turns labels back into text.
        'labels' => array_map('strval', array_keys($counts)),
        'values' => array_values($counts),
    ];
}

/** The $n rows with the largest positive value in column $valueCol, labelled by column $labelCol. */
function chart_top(array $table, int $labelCol, int $valueCol, int $n, string $title): array
{
    $rows = array_filter($table['rows'], fn(array $row): bool => $row[$valueCol] > 0);
    usort($rows, fn(array $a, array $b): int => $b[$valueCol] <=> $a[$valueCol]);
    $rows = array_slice($rows, 0, $n);

    return [
        'title'  => $title,
        'labels' => array_map(fn(array $row): string => (string) $row[$labelCol], $rows),
        'values' => array_column($rows, $valueCol),
    ];
}

/** Counts rows per year of a date column ("2021-04-30" counts for 2021). Years with nothing get a 0. */
function chart_by_year(array $table, int $dateCol, string $title): array
{
    $counts = [];
    foreach ($table['rows'] as $row) {
        $year = (int) substr((string) $row[$dateCol], 0, 4);
        if ($year > 0) {
            $counts[$year] = ($counts[$year] ?? 0) + 1;
        }
    }

    $labels = [];
    $values = [];
    if ($counts !== []) {
        // Fill the gaps, so a quiet year shows as a dip to zero instead of being skipped.
        for ($year = min(array_keys($counts)); $year <= max(array_keys($counts)); $year++) {
            $labels[] = (string) $year;
            $values[] = $counts[$year] ?? 0;
        }
    }
    return ['title' => $title, 'labels' => $labels, 'values' => $values];
}

/* ---------- Numbers in CSV cells (Analyze mode) ---------- */

/**
 * Reads a cell as a number: "42" → 42, "-3.5" → -3.5, "1,234,567" → 1234567.
 * Returns null for anything else: "", "N/A", "12 kg", "1,23".
 * Commas only count as thousands separators in correct groups of three, so "1,23"
 * (a decimal comma in many countries) is never misread as 123.
 */
function parse_number(string $text): int|float|null
{
    $text = trim($text);
    if (preg_match('/^[+-]?\d{1,3}(?:,\d{3})+(?:\.\d+)?\z/', $text) === 1) {
        $text = str_replace(',', '', $text);
    }
    if (!is_numeric($text)) {
        return null;
    }
    $int = filter_var($text, FILTER_VALIDATE_INT); // false for "2.5", "1e3" and numbers too big for an int
    if ($int !== false) {
        return $int;
    }
    $float = (float) $text;
    return is_finite($float) ? $float : null; // "1e999" is too big even for a float
}

/**
 * 'number' or 'text' for every column. A column holds numbers when at least
 * NUMERIC_THRESHOLD (80%) of its non-empty cells are numbers, so one "N/A" doesn't
 * turn a column of prices into text. A column with nothing in it counts as text.
 */
function column_types(array $table): array
{
    $types = [];
    foreach (array_keys($table['headers']) as $col) {
        $filled = 0;
        $numbers = 0;
        foreach ($table['rows'] as $row) {
            $cell = (string) $row[$col];
            if ($cell !== '') {
                $filled++;
                $numbers += parse_number($cell) !== null ? 1 : 0;
            }
        }
        $types[] = $filled > 0 && $numbers / $filled >= NUMERIC_THRESHOLD ? 'number' : 'text';
    }
    return $types;
}

/** The numbers in column $col. Empty cells and cells that aren't numbers are skipped. */
function column_numbers(array $table, int $col): array
{
    $numbers = [];
    foreach ($table['rows'] as $row) {
        $number = parse_number((string) $row[$col]);
        if ($number !== null) {
            $numbers[] = $number;
        }
    }
    return $numbers;
}

/** Count, sum, min, max, mean and median of a list of numbers. An empty list gives null for the last four. */
function number_stats(array $numbers): array
{
    $count = count($numbers);
    if ($count === 0) {
        return ['count' => 0, 'sum' => 0, 'min' => null, 'max' => null, 'mean' => null, 'median' => null];
    }
    sort($numbers);
    $sum = array_sum($numbers);
    $middle = intdiv($count, 2);
    return [
        'count'  => $count,
        'sum'    => $sum,
        'min'    => $numbers[0],
        'max'    => $numbers[$count - 1],
        'mean'   => $sum / $count,
        // The middle value, or the average of the two middle values when the count is even.
        'median' => $count % 2 === 1 ? $numbers[$middle] : ($numbers[$middle - 1] + $numbers[$middle]) / 2,
    ];
}

/**
 * Groups the rows by the value in column $groupCol and gives every group one number:
 * 'count' (its rows), 'sum' or 'avg' (of the numbers in column $valueCol).
 * Groups keep the order they first appear in. Rows with an empty group cell are skipped,
 * and so are rows without a number in $valueCol for 'sum' and 'avg'.
 */
function chart_group(array $table, int $groupCol, int $valueCol, string $calc, string $title): array
{
    $sums = [];
    $counts = [];
    foreach ($table['rows'] as $row) {
        $group = (string) $row[$groupCol];
        $value = $calc === 'count' ? 1 : parse_number((string) $row[$valueCol]);
        if ($group === '' || $value === null) {
            continue;
        }
        $sums[$group] = ($sums[$group] ?? 0) + $value;
        $counts[$group] = ($counts[$group] ?? 0) + 1;
    }

    $values = [];
    foreach ($sums as $group => $sum) {
        $values[] = $calc === 'avg' ? $sum / $counts[$group] : $sum;
    }
    return ['title' => $title, 'labels' => array_map('strval', array_keys($sums)), 'values' => $values];
}
