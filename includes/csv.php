<?php
declare(strict_types=1);

/*
 * CSV uploads: check the uploaded file, then turn its text into the app's one table shape.
 *
 *     ['headers' => ['city', 'sales'], 'rows' => [['Pune', '120'], ['Delhi', '45']]]
 *
 * Cells stay exactly as the file wrote them (strings). stats.php decides which columns
 * hold numbers and reads them with parse_number().
 *
 * SECURITY: the file is read straight from PHP's temporary upload folder and never moved
 * or saved. PHP deletes that temporary file by itself when the request ends.
 *
 * The way back, a table to a CSV download (export.php), is csv_write() at the end of this file.
 */

// A constant expression: PHP works out "2" from the setting in config.php once, at compile time.
const CSV_TOO_BIG = 'That file is bigger than the ' . UPLOAD_MAX_BYTES / 1048576 . ' MB upload limit.';

/**
 * Checks one uploaded file from $_FILES. Returns '' when it is a CSV file we can read,
 * otherwise a message for the visitor.
 */
function csv_upload_error(mixed $file): string
{
    // A crafted request can send csv[]=..., which turns every field into an array,
    // so check the shape before trusting anything in it.
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

    // SECURITY: is_uploaded_file() confirms PHP itself received this file in this request,
    // so a forged path such as "/etc/passwd" can never be read in its place.
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

    // The extension is just part of the name the visitor gave the file. fileinfo looks at the
    // bytes inside: a renamed picture or program is not text, so it is turned away here.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!is_string($mime) || !(str_starts_with($mime, 'text/') || $mime === 'application/csv')) {
        return 'That file isn’t a text CSV file. Save it from your spreadsheet app as “CSV” and try again.';
    }
    return '';
}

/**
 * Turns the text of a CSV file into the table shape.
 * Returns ['table' => [...], 'notes' => [...]] or ['error' => a friendly message].
 * 'notes' lists what was changed or left out, so the page can say so.
 *
 * - A UTF-8 byte order mark (Excel adds one) is removed.
 * - Text that isn't valid UTF-8 is converted from Windows-1252, the encoding Excel uses
 *   for "CSV (Comma delimited)" on Windows.
 * - Blank lines are skipped, short rows are padded with '' and every row gets the same width.
 * - The first row is the header row, unless one of its cells is a number: then it is data,
 *   and the columns are named "Column 1", "Column 2"...
 * - At most CSV_MAX_ROWS data rows and CSV_MAX_COLS columns are kept.
 */
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

    // fgetcsv() reads from a file handle, so put the text in a memory "file".
    // It understands quoted cells, including commas and line breaks inside quotes.
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $text);
    rewind($stream);

    $rows = [];
    // Read one row more than the limit allows (plus the header), so we can tell the file was cut.
    while (count($rows) < CSV_MAX_ROWS + 2 && ($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
        // The last argument, escape '', means plain RFC 4180 CSV: a quote inside a quoted cell
        // is written "". PHP 8.4+ also wants that argument spelled out instead of left to its default.
        if ($cells === [null]) {
            continue; // fgetcsv() returns [null] for a blank line
        }
        $cells = array_map('trim', $cells);
        if (implode('', $cells) !== '') { // ",,," has cells, but nothing in them
            $rows[] = $cells;
        }
    }
    fclose($stream);

    if ($rows === []) {
        return ['error' => 'That file has no rows to read.'];
    }

    // Every row gets the width of the widest row, so short rows can't shift the columns.
    $width = max(array_map('count', $rows));
    if ($width > CSV_MAX_COLS) {
        $width = CSV_MAX_COLS;
        $notes[] = 'Only the first ' . CSV_MAX_COLS . ' columns were read';
    }
    $rows = array_map(fn(array $row): array => array_pad(array_slice($row, 0, $width), $width, ''), $rows);

    // A header row holds names, not numbers.
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

/**
 * Makes one cell safe to open in a spreadsheet app.
 *
 * SECURITY (CSV formula injection): Excel, LibreOffice and Google Sheets run a cell that starts
 * with = + - or @ as a formula, and a tab or carriage return can hide such a start. A repository
 * named "=HYPERLINK(...)" or a crafted CSV cell could then do harm on the machine of whoever opens
 * the export. A leading ' makes the app show the cell as plain text instead.
 * Numbers such as -3.5 are left alone: a spreadsheet reads them as numbers, never as formulas.
 */
function csv_safe_cell(mixed $value): string
{
    $text = (string) $value; // null becomes '', 2.5 becomes "2.5"
    if (is_int($value) || is_float($value) || is_numeric($text)) {
        return $text;
    }
    return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'" . $text : $text;
}

/** Writes a table (headers first, then every row) as CSV to an open stream, every cell made safe. */
function csv_write(array $table, mixed $stream): void
{
    foreach ([$table['headers'], ...$table['rows']] as $row) {
        // escape '' writes plain RFC 4180 CSV, the same rule csv_parse() reads with.
        fputcsv($stream, array_map('csv_safe_cell', $row), ',', '"', '');
    }
}
