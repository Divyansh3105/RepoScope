<?php

declare(strict_types=1);

// CSV upload checks and parsing, plus csv_write() for exports.
// Cells are kept as strings; stats.php works out which columns are numbers.
// Uploads are read from PHP's temp file and never stored.

const CSV_TOO_BIG = 'That file is bigger than the ' . UPLOAD_MAX_BYTES / 1048576 . ' MB upload limit.';

// Returns '' if the upload is a readable CSV file, otherwise an error message.
function csv_upload_error(mixed $file): string
{
    // csv[]=... in a crafted request turns every field into an array
    if (!is_array($file) || !is_int($file['error'] ?? null)) {
        return 'Choose a CSV file to upload.';
    }
    $problem = match ($file['error']) {
        UPLOAD_ERR_OK                             => '',
        UPLOAD_ERR_NO_FILE                        => 'Choose a CSV file to upload.',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => CSV_TOO_BIG,
        default                                   => 'The upload failed. Please try again.',
    };
    if ($problem !== '') {
        return $problem;
    }

    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        return 'The upload failed. Please try again.';
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        return CSV_TOO_BIG;
    }
    if ($file['size'] === 0) {
        return 'That file is empty.';
    }
    if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        return 'Only .csv files can be analyzed.';
    }

    // the extension is just a name; check what the bytes actually are
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!is_string($mime) || !(str_starts_with($mime, 'text/') || $mime === 'application/csv')) {
        return "That file isn't a text CSV file. Save it from your spreadsheet app as CSV and try again.";
    }
    return '';
}

// Parses CSV text into ['table' => [...], 'notes' => [...]] or ['error' => message].
// Strips a BOM, converts Windows-1252 (Excel's default) to UTF-8, skips blank lines and
// pads short rows. The first row is the header unless it contains a number.
function csv_parse(string $text): array
{
    $notes = [];
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
        $text = substr($text, 3);
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        $notes[] = 'Converted from Windows-1252 to UTF-8';
    }

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $text);
    rewind($stream);

    $rows = [];
    // read up to 2 rows past the limit (header + 1) so we know if the file got cut
    while (count($rows) < CSV_MAX_ROWS + 2 && ($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
        if ($cells === [null]) {
            continue; // blank line
        }
        $cells = array_map('trim', $cells);
        if (implode('', $cells) !== '') { // skip rows like ",,,"
            $rows[] = $cells;
        }
    }
    fclose($stream);

    if ($rows === []) {
        return ['error' => 'That file has no rows to read.'];
    }

    $width = max(array_map('count', $rows));
    if ($width > CSV_MAX_COLS) {
        $width = CSV_MAX_COLS;
        $notes[] = 'Only the first ' . CSV_MAX_COLS . ' columns were read';
    }
    $rows = array_map(fn(array $row): array => array_pad(array_slice($row, 0, $width), $width, ''), $rows);

    $hasHeader = array_filter($rows[0], fn(string $cell): bool => parse_number($cell) !== null) === [];
    $first = $hasHeader ? array_shift($rows) : [];
    if (!$hasHeader) {
        $notes[] = 'The first row holds numbers, so it was read as data and the columns were numbered';
    }
    $headers = [];
    for ($i = 0; $i < $width; $i++) {
        $headers[] = ($first[$i] ?? '') !== '' ? $first[$i] : 'Column ' . ($i + 1);
    }

    if ($rows === []) {
        return ['error' => 'That file has a header row but no data under it.'];
    }
    if (count($rows) > CSV_MAX_ROWS) {
        $rows = array_slice($rows, 0, CSV_MAX_ROWS);
        $notes[] = 'Only the first ' . number_format(CSV_MAX_ROWS) . ' rows were read';
    }
    return ['table' => ['headers' => $headers, 'rows' => $rows], 'notes' => $notes];
}

// Formula injection: spreadsheets run cells starting with = + - @ (or a tab/CR before them)
// as formulas, so prefix those with a quote. Real numbers like -3.5 are left as they are.
function csv_safe_cell(mixed $value): string
{
    $text = (string) $value;
    if (is_int($value) || is_float($value) || is_numeric($text)) {
        return $text;
    }
    return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'" . $text : $text;
}

function csv_write(array $table, mixed $stream): void
{
    foreach ([$table['headers'], ...$table['rows']] as $row) {
        fputcsv($stream, array_map('csv_safe_cell', $row), ',', '"', '');
    }
}
