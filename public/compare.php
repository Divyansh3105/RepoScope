<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// Compare: POST takes a CSV of usernames, looks each one up and stores the leaderboard
// in the session, then redirects. GET renders it.

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = CSV_TOO_BIG; // over post_max_size
    } elseif (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'This form expired. Please choose the file and upload it again.';
    } else {
        $error = csv_upload_error($_FILES['csv'] ?? null);
        if ($error === '') {
            $parsed = csv_parse((string) file_get_contents($_FILES['csv']['tmp_name']));
            $error = $parsed['error'] ?? '';
        }
        if ($error === '') {
            $names = compare_usernames($parsed['table']);
            if ($names['users'] === []) {
                $error = 'That file has no GitHub usernames. Put them in a column named username, or in the first column.';
            }
        }
    }

    if ($error === '') {
        // a failed user doesn't stop the rest; cached users cost no requests
        $entries = [];
        $failed = [];
        foreach ($names['users'] as $username) {
            $result = github_fetch_user($username);
            if (isset($result['error'])) {
                $failed[] = [$username, $result['error']];
            } else {
                $entries[] = $result;
            }
        }

        $notes = $parsed['notes'];
        if ($names['total'] > COMPARE_MAX_USERS) {
            $notes[] = 'Only the first ' . COMPARE_MAX_USERS . " of {$names['total']} usernames were compared";
        }
        $invalid = count($names['invalid']);
        if ($invalid > 0) {
            $notes[] = $invalid . ($invalid === 1 ? " cell isn't a username" : " cells aren't usernames") . ' and '
                . ($invalid === 1 ? 'was' : 'were') . ' skipped: ' . implode(', ', array_slice($names['invalid'], 0, 5))
                . ($invalid > 5 ? '...' : '');
        }
        $cached = count(array_filter(array_column($entries, 'cached')));

        $_SESSION['compare'] = [
            'name'      => mb_substr(basename((string) $_FILES['csv']['name']), 0, 100),
            'table'     => compare_table($entries),
            // all of the group's repos together, by language (column 1 of repos_table)
            'languages' => chart_count(repos_table(array_merge(...array_column($entries, 'repos'))), 1, 'Languages across the group'),
            'failed'    => $failed,
            'notes'     => $notes,
            'cached'    => $cached,
            'fetched'   => count($entries) - $cached,
        ];
        // only animate when something new came from GitHub
        $_SESSION['compare_fresh'] = count($entries) > $cached;

        header('Location: compare.php', true, 303);
        exit;
    }
    http_response_code(400);
}

$compare = $_SESSION['compare'] ?? null;
$fresh = !empty($_SESSION['compare_fresh']);
unset($_SESSION['compare_fresh']);
$rate = $_SESSION['github_rate'] ?? null;

session_write_close();

if ($compare !== null) {
    $table = $compare['table'];
    $col = array_flip($table['headers']);
    $users = count($table['rows']);

    $stars = chart_top($table, $col['User'], $col['Stars'], COMPARE_MAX_USERS, 'Stars');
    $followers = chart_top($table, $col['User'], $col['Followers'], COMPARE_MAX_USERS, 'Followers');
    $repos = chart_top($table, $col['User'], $col['Repositories'], COMPARE_MAX_USERS, 'Repositories');

    $userCell = fn(mixed $login): string =>
    '<a href="profile.php?user=' . e(rawurlencode((string) $login)) . '">' . e((string) $login) . '</a>';
}

render_header('Compare', 'compare');
?>
<section class="page-head">
    <div>
        <h1>Compare</h1>
        <p>Upload a CSV of up to <?= COMPARE_MAX_USERS ?> GitHub usernames to rank them and chart the whole group.
            Start from the <a href="compare-template.csv" download>template file</a>.</p>
    </div>
    <form class="search" action="compare.php" method="post" enctype="multipart/form-data" data-pending="Looking up...">
        <?= csrf_field() ?>
        <label for="csv">Usernames CSV <span class="muted">(up to <?= COMPARE_MAX_USERS ?> users)</span></label>
        <div class="field-row">
            <input id="csv" name="csv" type="file" accept=".csv,text/csv" required
                <?= $error !== '' ? 'aria-invalid="true" aria-describedby="upload-error"' : '' ?>>
            <button class="btn" type="submit"><span class="t-text-swap">Compare</span></button>
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

<?php if ($compare !== null): ?>
    <ul class="status" aria-label="About this comparison">
        <li><?= e($compare['name']) ?></li>
        <li><?= $users ?> <?= $users === 1 ? 'user' : 'users' ?> compared</li>
        <?php if ($compare['cached'] > 0): ?>
            <li><?= $compare['cached'] ?> from your session cache</li>
        <?php endif; ?>
        <?php if ($compare['fetched'] > 0): ?>
            <li><?= $compare['fetched'] ?> fetched from GitHub</li>
        <?php endif; ?>
        <?php if ($rate !== null && $rate['reset'] > time()): ?>
            <li>GitHub API: <?= $rate['remaining'] ?> of <?= $rate['limit'] ?> requests left this hour</li>
        <?php endif; ?>
        <li>Forks left out of every number</li>
        <?php foreach ($compare['notes'] as $note): ?>
            <li><?= e($note) ?></li>
        <?php endforeach; ?>
    </ul>

    <?php if ($compare['failed'] !== []): ?>
        <section class="panel">
            <h2>Not included <span class="muted">(<?= count($compare['failed']) ?>)</span></h2>
            <ul class="rules">
                <?php foreach ($compare['failed'] as [$username, $reason]): ?>
                    <li><strong><?= e($username) ?></strong>: <?= e($reason) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($users > 0): ?>
        <section class="panel">
            <div class="panel-head">
                <h2>Leaderboard</h2>
                <?= export_link('table=compare', 'leaderboard') ?>
            </div>
            <p class="chart-note">Ranked by stars, then followers. Up to <?= GITHUB_MAX_PAGES * 100 ?> repositories per user are counted.</p>
            <?php render_table($table, [$col['User'] => $userCell]); ?>
        </section>

        <section class="chart-grid" aria-label="Charts"<?= $fresh ? ' data-animate' : '' ?>>
            <?php render_chart(
                'hbar',
                $stars,
                ['User', 'Stars'],
                note: "Stars across each user's own repositories. Users with none are left out."
            ); ?>
            <?php render_chart(
                'hbar',
                $followers,
                ['User', 'Followers'],
                note: 'Followers on GitHub. Users with none are left out.'
            ); ?>
            <?php render_chart(
                'hbar',
                $repos,
                ['User', 'Repositories'],
                note: "Each user's own public repositories, forks left out."
            ); ?>
            <?php render_chart(
                'pie',
                $compare['languages'],
                ['Language', 'Repositories'],
                note: 'Every repository in the group counted once, by its main language. Repositories without one are left out.'
            ); ?>
        </section>
    <?php endif; ?>
<?php elseif ($error === ''): ?>
    <section class="panel">
        <h2>Start with a list of usernames</h2>
        <ul class="rules">
            <li>Put the usernames in a column named <strong>username</strong>, or in the first column. The <a href="compare-template.csv" download>template file</a> shows the layout.</li>
            <li>Up to <?= COMPARE_MAX_USERS ?> different users; a leading @ and repeated names are fine.</li>
            <li>Users looked up in the last <?= CACHE_TTL / 60 ?> minutes come from your session and cost no GitHub requests. Every other user costs 1 to <?= GITHUB_MAX_PAGES + 1 ?> of the requests GitHub allows each hour.</li>
            <li>If one user can't be loaded, the others still are, and the page says why.</li>
        </ul>
    </section>
<?php endif; ?>
<?php
render_footer();
