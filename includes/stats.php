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
