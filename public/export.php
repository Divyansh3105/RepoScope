<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// CSV download of a result table from the session: export.php?table=compare

$which = pick($_GET['table'] ?? null, ['profile', 'compare', 'analyze', 'analyze-numbers', 'analyze-text'], '');

$table = null;
$filename = '';
if ($which === 'profile') {
    // read the cache entry directly (ignoring the TTL) so the file matches what was on screen
    $user = is_string($_GET['user'] ?? null) ? $_GET['user'] : '';
    $entry = is_valid_username($user) ? ($_SESSION['github_cache']['user:' . strtolower($user)]['data'] ?? null) : null;
    if ($entry !== null) {
        $table = repos_table($entry['repos']);
        $filename = $user . '-repositories';
    }
} elseif ($which === 'compare' && isset($_SESSION['compare'])) {
    $table = $_SESSION['compare']['table'];
    $filename = 'compare-leaderboard';
} elseif ($which !== '' && isset($_SESSION['analyze'])) {
    $data = $_SESSION['analyze']['table'];
    $types = column_types($data);
    // only [A-Za-z0-9_-] from the uploaded name goes into the Content-Disposition header
    $base = trim(substr((string) preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '-',
        pathinfo($_SESSION['analyze']['name'], PATHINFO_FILENAME)
    ), 0, 60), '-') ?: 'analyze';
    [$table, $filename] = match ($which) {
        'analyze'         => [$data, $base . '-data'],
        'analyze-numbers' => [number_summary($data, array_keys($types, 'number')), $base . '-number-columns'],
        'analyze-text'    => [text_summary($data, array_keys($types, 'text')), $base . '-text-columns'],
    };
}

session_write_close();

if ($table === null) {
    http_response_code(404);
    render_header('Export');
?>
    <div class="alert" role="alert">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="10" cy="10" r="8" />
            <path d="M10 6v5M10 14h.01" />
        </svg>
        <p><strong>Nothing to export:</strong> that table isn't in your session any more.
            Open <a href="profile.php">Profile</a>, <a href="compare.php">Compare</a> or
            <a href="analyze.php">Analyze</a> again and use the table's Export CSV link.
        </p>
    </div>
<?php
    render_footer();
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM, otherwise Excel opens it as ANSI
csv_write($table, $out);
fclose($out);
