<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// The username comes from the URL: profile.php?user=octocat. It may be missing, or even an
// array (?user[]=x), so only a string is accepted. A leading "@" is dropped, since people type one.
$username = is_string($_GET['user'] ?? null) ? ltrim(trim($_GET['user']), '@') : '';

$error = '';
$result = null;
if ($username !== '') {
    if (!is_valid_username($username)) {
        $error = 'That isn’t a valid GitHub username. Usernames are up to 39 letters, digits '
            . 'and single hyphens, and can’t start or end with a hyphen.';
    } else {
        $result = github_fetch_user($username); // reads and writes the session cache
        if (isset($result['error'])) {
            $error = $result['error'];
            $result = null;
        }
    }
}
$rate = $_SESSION['github_rate'] ?? null;

// Everything that touches the session is done: release its lock before printing the page.
session_write_close();

if ($result !== null) {
    // Turn the GitHub data into the app's table shape, then build every chart from that table.
    $profile = $result['profile'];
    $table = repos_table($result['repos']);
    $col = array_flip($table['headers']); // column name => position, e.g. $col['Stars'] is 2

    $languages = chart_count($table, $col['Language'], 'Languages');
    $topStars = chart_top($table, $col['Repository'], $col['Stars'], 10, 'Most-starred repositories');
    $timeline = chart_by_year($table, $col['Created'], 'Repositories created per year');

    $forks = count($result['repos']) - count($table['rows']);
    $minutesOld = intdiv(time() - $result['fetched'], 60);
    $stats = [
        'Public repositories' => $profile['repos'],
        'Stars earned'        => array_sum(array_column($table['rows'], $col['Stars'])),
        'Followers'           => $profile['followers'],
        'Following'           => $profile['following'],
        'Languages'           => count($languages['labels']),
    ];

    // GitHub often stores a website as "example.com", without https://.
    $blogUrl = '';
    if ($profile['blog'] !== '') {
        $blogUrl = safe_url(preg_match('~^https?://~i', $profile['blog']) ? $profile['blog'] : 'https://' . $profile['blog']);
    }
}

render_header($result !== null ? $profile['login'] . ' · Profile' : 'Profile', 'profile');
?>
<section class="page-head">
    <div>
        <h1>Profile</h1>
        <p>Languages, most-starred repositories and activity for any GitHub user.</p>
    </div>
    <form action="profile.php" method="get" role="search">
        <label for="user">GitHub username</label>
        <div class="input-row">
            <input id="user" name="user" type="text" required maxlength="40" value="<?= e($username) ?>"
                   placeholder="e.g. torvalds" autocomplete="off" autocapitalize="off" spellcheck="false">
            <button class="btn" type="submit">Analyze</button>
        </div>
    </form>
</section>

<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><strong>Error:</strong> <?= e($error) ?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <section class="card profile">
        <?php if (safe_url($profile['avatar']) !== ''): ?>
            <img class="avatar" src="<?= e($profile['avatar']) ?>" alt="" width="88" height="88">
        <?php endif; ?>
        <div class="profile-text">
            <h2><?= e($profile['name'] !== '' ? $profile['name'] : $profile['login']) ?></h2>
            <!-- Built from the login ourselves, so the link can only ever point at github.com. -->
            <a href="https://github.com/<?= e(rawurlencode($profile['login'])) ?>">@<?= e($profile['login']) ?> on GitHub</a>
            <?php if ($profile['bio'] !== ''): ?>
                <p class="profile-bio"><?= e($profile['bio']) ?></p>
            <?php endif; ?>
            <ul class="profile-meta">
                <?php if ($profile['company'] !== ''): ?><li><?= e($profile['company']) ?></li><?php endif; ?>
                <?php if ($profile['location'] !== ''): ?><li><?= e($profile['location']) ?></li><?php endif; ?>
                <?php if ($blogUrl !== ''): ?><li><a href="<?= e($blogUrl) ?>" rel="nofollow ugc"><?= e($profile['blog']) ?></a></li><?php endif; ?>
                <li>Joined <?= e(substr($profile['joined'], 0, 4)) ?></li>
            </ul>
        </div>
    </section>

    <section class="stats" aria-label="Summary">
        <?php foreach ($stats as $label => $value): ?>
            <div class="stat">
                <span class="stat-value"><?= number_format($value) ?></span>
                <span class="stat-label"><?= e($label) ?></span>
            </div>
        <?php endforeach; ?>
    </section>

    <p class="muted">
        <?php if ($result['cached']): ?>
            From the session cache (fetched <?= $minutesOld === 0 ? 'under a minute' : $minutesOld . ' min' ?> ago).
        <?php else: ?>
            Fetched from GitHub just now.
        <?php endif; ?>
        <?php if ($forks > 0): ?>
            <?= $forks ?> fork<?= $forks === 1 ? ' is' : 's are' ?> left out of the charts and totals.
        <?php endif; ?>
        <?php if ($profile['repos'] > GITHUB_MAX_PAGES * 100): ?>
            Only the <?= GITHUB_MAX_PAGES * 100 ?> most recently updated repositories are included.
        <?php endif; ?>
        <?php if ($rate !== null && $rate['reset'] > time()): ?>
            GitHub API: <?= $rate['remaining'] ?> of <?= $rate['limit'] ?> requests left this hour.
        <?php endif; ?>
    </p>

    <section class="chart-grid" aria-label="Charts">
        <?php render_chart('pie', $languages, ['Language', 'Repositories']); ?>
        <?php render_chart('bar', $topStars, ['Repository', 'Stars']); ?>
        <?php render_chart('line', $timeline, ['Year', 'Repositories created'], 'chart-wide'); ?>
    </section>

    <section class="card">
        <h2>Repositories <span class="muted">(<?= count($table['rows']) ?>)</span></h2>
        <?php render_table($table); ?>
    </section>
<?php elseif ($error === ''): ?>
    <section class="card">
        <h2>Start with a username</h2>
        <p class="muted">Type any GitHub username above, or try
            <a href="profile.php?user=torvalds">torvalds</a>,
            <a href="profile.php?user=gaearon">gaearon</a> or
            <a href="profile.php?user=octocat">octocat</a>.</p>
    </section>
<?php endif; ?>
<?php
render_footer();
