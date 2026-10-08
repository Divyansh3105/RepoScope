<?php

declare(strict_types=1);

// Stats and chart data from a table (['headers' => [...], 'rows' => [[...], ...]]).
// Chart builders return ['title' => ..., 'labels' => [...], 'values' => [...]] for charts.js.

// How often each non-empty value appears in a column, most common first.
function chart_count(array $table, int $col, string $title): array
{
    $counts = [];
    foreach ($table['rows'] as $row) {
        $value = (string) ($row[$col] ?? '');
        if ($value !== '') {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
    }
    arsort($counts);

    return [
        'title'  => $title,
        'labels' => array_map('strval', array_keys($counts)), // keys like "2024" come back as ints
        'values' => array_values($counts),
    ];
}

// Top $n rows by a column (values > 0 only).
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

// Rows per year of a YYYY-MM-DD column, with empty years filled in as 0.
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
        for ($year = min(array_keys($counts)); $year <= max(array_keys($counts)); $year++) {
            $labels[] = (string) $year;
            $values[] = $counts[$year] ?? 0;
        }
    }
    return ['title' => $title, 'labels' => $labels, 'values' => $values];
}

// --- CSV numbers (Analyze) ---

// "42" -> 42, "-3.5" -> -3.5, "1,234,567" -> 1234567, anything else -> null.
// Commas only count as thousands separators in groups of 3, so "1,23" stays text.
function parse_number(string $text): int|float|null
{
    $text = trim($text);
    if (preg_match('/^[+-]?\d{1,3}(?:,\d{3})+(?:\.\d+)?\z/', $text) === 1) {
        $text = str_replace(',', '', $text);
    }
    if (!is_numeric($text)) {
        return null;
    }
    $int = filter_var($text, FILTER_VALIDATE_INT); // false for 2.5, 1e3 or too big for int
    if ($int !== false) {
        return $int;
    }
    $float = (float) $text;
    return is_finite($float) ? $float : null; // e.g. 1e999
}

// 'number' or 'text' per column. A column is numeric if at least NUMERIC_THRESHOLD of its
// filled cells parse as numbers, so a stray "N/A" doesn't make it text.
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
        'median' => $count % 2 === 1 ? $numbers[$middle] : ($numbers[$middle - 1] + $numbers[$middle]) / 2,
    ];
}

// Groups rows by $groupCol and gives each group a count, sum or avg of $valueCol.
// Empty groups and non-numeric values (for sum/avg) are skipped.
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

// One row of stats per number column. Skipped = empty or non-numeric cells.
function number_summary(array $table, array $numberCols): array
{
    $summary = ['headers' => ['Column', 'Count', 'Skipped', 'Sum', 'Min', 'Max', 'Mean', 'Median'], 'rows' => []];
    foreach ($numberCols as $col) {
        $stats = number_stats(column_numbers($table, $col));
        $summary['rows'][] = [
            $table['headers'][$col],
            $stats['count'],
            count($table['rows']) - $stats['count'],
            $stats['sum'],
            $stats['min'],
            $stats['max'],
            $stats['mean'],
            $stats['median']
        ];
    }
    return $summary;
}

// One row per text column: filled/empty/unique counts and the 10 most common values.
function text_summary(array $table, array $textCols): array
{
    $summary = ['headers' => ['Column', 'Count', 'Empty', 'Unique', 'Most common (up to 10)'], 'rows' => []];
    foreach ($textCols as $col) {
        $counts = chart_count($table, $col, '');
        $filled = array_sum($counts['values']);
        $top = array_map(
            fn(string $value, int $n): string => "{$value} ({$n})",
            array_slice($counts['labels'], 0, 10),
            array_slice($counts['values'], 0, 10)
        );
        $summary['rows'][] = [
            $table['headers'][$col],
            $filled,
            count($table['rows']) - $filled,
            count($counts['labels']),
            implode(' · ', $top)
        ];
    }
    return $summary;
}
