<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// ?user[]=x would give an array, so only accept a string. Drop a leading @.
$username = is_string($_GET['user'] ?? null) ? ltrim(trim($_GET['user']), '@') : '';

$error = '';
$fieldError = false; // mark the input as invalid
$result = null;
if ($username !== '') {
    if (!is_valid_username($username)) {
        $error = "That isn't a valid GitHub username. Usernames are up to 39 letters, digits "
            . "and single hyphens, and can't start or end with a hyphen.";
        $fieldError = true;
    } else {
        $result = github_fetch_user($username);
        if (isset($result['error'])) {
            $error = $result['error'];
            $fieldError = ($result['status'] ?? 0) === 404;
            $result = null;
        } else {
            // last 5 lookups for the home page
            $recent = array_merge([$result['profile']['login']], $_SESSION['recent'] ?? []);
            $_SESSION['recent'] = array_slice(array_values(array_unique($recent)), 0, 5);
        }
    }
}
$rate = $_SESSION['github_rate'] ?? null;

session_write_close();

if ($result !== null) {
    $profile = $result['profile'];
    $table = repos_table($result['repos']);
    $col = array_flip($table['headers']); // name => index

    $languages = chart_count($table, $col['Language'], 'Languages');
    $topStars = chart_top($table, $col['Repository'], $col['Stars'], 10, 'Most-starred repositories');
    $timeline = chart_by_year($table, $col['Created'], 'Repositories created per year');

    $forks = count($result['repos']) - count($table['rows']);
    $minutesOld = intdiv(time() - $result['fetched'], 60);
    $facts = [
        'Public repositories' => $profile['repos'],
        'Stars earned'        => array_sum(array_column($table['rows'], $col['Stars'])),
        'Followers'           => $profile['followers'],
        'Following'           => $profile['following'],
    ];

    // a language keeps the same colour everywhere on the page (its rank in the language chart)
    $slots = array_flip($languages['labels']);
    $languageCell = fn(mixed $language): string => $language === ''
        ? '<span class="muted">—</span>'
        : '<span class="cell-line">' . line_bullet((string) $language, $slots[$language] ?? 8, 'bullet-sm') . e((string) $language) . '</span>';

    // the blog field is often just "example.com"
    $blogUrl = '';
    if ($profile['blog'] !== '') {
        $blogUrl = safe_url(preg_match('~^https?://~i', $profile['blog']) ? $profile['blog'] : 'https://' . $profile['blog']);
    }
}

render_header(
    $result !== null ? $profile['login'] . ' · Profile' : 'Profile',
    'profile',
    $result !== null ? [
        'description' => 'GitHub profile of ' . ($profile['name'] !== '' ? $profile['name'] . ' (@' . $profile['login'] . ')' : '@' . $profile['login'])
            . ': languages, most-starred repositories and repositories created per year.',
        'query' => 'user=' . rawurlencode($profile['login']),
    ] : []
);
?>
<section class="page-head">
    <div>
        <h1>Profile</h1>
        <p>Languages, most-starred repositories and activity for any GitHub user.</p>
    </div>
    <form class="search" action="profile.php" method="get" role="search" data-pending="Looking up...">
        <label for="user">GitHub username</label>
        <div class="field-row">
            <input id="user" name="user" type="text" required maxlength="40" value="<?= e($username) ?>"
                placeholder="e.g. torvalds" autocomplete="off" autocapitalize="off" spellcheck="false"
                <?= $fieldError ? 'aria-invalid="true" aria-describedby="lookup-error"' : '' ?>>
            <button class="btn" type="submit"><span class="t-text-swap">Analyze</span></button>
        </div>
    </form>
</section>

<?php if ($error !== ''): ?>
    <div class="alert" id="lookup-error" role="alert">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="10" cy="10" r="8" />
            <path d="M10 6v5M10 14h.01" />
        </svg>
        <p><strong>Error:</strong> <?= e($error) ?></p>
    </div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <section class="panel profile" aria-label="Profile">
        <?php if (safe_url($profile['avatar']) !== ''): ?>
            <img class="avatar" src="<?= e($profile['avatar']) ?>" alt="" width="96" height="96">
        <?php endif; ?>
        <div>
            <h2 class="profile-name"><?= e($profile['name'] !== '' ? $profile['name'] : $profile['login']) ?></h2>
            <a class="profile-handle" href="https://github.com/<?= e(rawurlencode($profile['login'])) ?>">@<?= e($profile['login']) ?> on GitHub</a>
            <?php if ($profile['bio'] !== ''): ?>
                <p class="profile-bio"><?= e($profile['bio']) ?></p>
            <?php endif; ?>
            <ul class="profile-meta">
                <?php if ($profile['company'] !== ''): ?><li><?= e($profile['company']) ?></li><?php endif; ?>
                <?php if ($profile['location'] !== ''): ?><li><?= e($profile['location']) ?></li><?php endif; ?>
                <?php if ($blogUrl !== ''): ?><li><a href="<?= e($blogUrl) ?>" rel="nofollow ugc"><?= e($profile['blog']) ?></a></li><?php endif; ?>
                <li>Joined <?= e(substr($profile['joined'], 0, 4)) ?></li>
            </ul>
            <?php if ($languages['labels'] !== []): ?>
                <ul class="lines" aria-label="Languages, most used first">
                    <?php foreach (array_slice($languages['labels'], 0, 8) as $slot => $language): ?>
                        <li><?= line_bullet($language, $slot) ?><?= e($language) ?></li>
                    <?php endforeach; ?>
                    <?php if (count($languages['labels']) > 8): ?>
                        <li class="muted">+<?= count($languages['labels']) - 8 ?> more</li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        </div>
        <dl class="facts">
            <?php foreach ($facts as $label => $value): ?>
                <div class="fact">
                    <dt><?= e($label) ?></dt>
                    <dd><?= number_format($value) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>

    <ul class="status" aria-label="About this data">
        <li><?= $result['cached']
                ? 'Cached, fetched ' . ($minutesOld === 0 ? 'under a minute' : $minutesOld . ' min') . ' ago'
                : 'Fetched from GitHub just now' ?></li>
        <?php if ($rate !== null && $rate['reset'] > time()): ?>
            <li>GitHub API: <?= $rate['remaining'] ?> of <?= $rate['limit'] ?> requests left this hour</li>
        <?php endif; ?>
        <?php if ($forks > 0): ?>
            <li><?= $forks ?> fork<?= $forks === 1 ? '' : 's' ?> left out of the charts and totals</li>
        <?php endif; ?>
        <?php if ($profile['repos'] > GITHUB_MAX_PAGES * 100): ?>
            <li>Only the <?= GITHUB_MAX_PAGES * 100 ?> most recently updated repositories are included</li>
        <?php endif; ?>
    </ul>

    <section class="chart-grid" aria-label="Charts" <?= $result['cached'] ? '' : ' data-animate' ?>>
        <?php render_chart(
            'pie',
            $languages,
            ['Language', 'Repositories'],
            note: 'Each repository counted once, by its main language. Repositories without one are left out.'
        ); ?>
        <?php render_chart(
            'hbar',
            $topStars,
            ['Repository', 'Stars'],
            note: 'Up to 10 repositories with the most stars.'
        ); ?>
        <?php render_chart(
            'line',
            $timeline,
            ['Year', 'Repositories created'],
            'chart-wide',
            note: 'How many repositories were created in each year.'
        ); ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Repositories <span class="muted">(<?= count($table['rows']) ?>)</span></h2>
            <?= export_link('table=profile&user=' . rawurlencode($profile['login']), 'repositories') ?>
        </div>
        <?php render_table($table, [$col['Language'] => $languageCell]); ?>
    </section>
<?php elseif ($error === ''): ?>
    <section class="panel">
        <h2>Start with a username</h2>
        <p class="muted">Type any GitHub username above, or try
            <a href="profile.php?user=torvalds">torvalds</a>,
            <a href="profile.php?user=gaearon">gaearon</a> or
            <a href="profile.php?user=octocat">octocat</a>.
        </p>
    </section>
<?php endif; ?>
<?php
render_footer();
