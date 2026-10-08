<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// recent lookups saved by profile.php, re-validated before they become links
$recent = array_values(array_filter(
    is_array($_SESSION['recent'] ?? null) ? $_SESSION['recent'] : [],
    fn(mixed $name): bool => is_string($name) && is_valid_username($name)
));

session_write_close();

$examples = ['torvalds', 'gaearon', 'octocat'];

// [line key, bullet letter, colour slot, title, text, link]
$routes = [
    ['p', 'P', 0, 'Profile', 'One developer: their languages, most-starred repositories and how their work grew year by year.', 'profile.php'],
    ['c', 'C', 1, 'Compare', 'Up to 15 GitHub usernames from a CSV file: a leaderboard and charts for the whole group.', 'compare.php'],
    ['a', 'A', 2, 'Analyze', 'Any CSV file: which columns hold numbers or text, summary statistics and a chart of the columns you pick.', 'analyze.php'],
];

function profile_chip(string $username): string
{
    return '<li><a class="chip" href="profile.php?user=' . e(rawurlencode($username)) . '">' . e($username) . '</a></li>';
}

render_header('Home', 'home');
?>
<div class="home-hero">
    <section class="intro">
        <h1>GitHub profiles and CSV files, turned into tables and charts.</h1>
        <p>Look up a developer, compare a group, or analyze any CSV. RepoScope converts each one into the same table, then works out the statistics and draws the charts.</p>

        <form class="search" action="profile.php" method="get" role="search" data-pending="Looking up...">
            <label for="user">Look up a GitHub user</label>
            <div class="field-row">
                <input id="user" name="user" type="text" required maxlength="40"
                    placeholder="e.g. torvalds" autocomplete="off" autocapitalize="off" spellcheck="false">
                <button class="btn" type="submit"><span class="t-text-swap">Analyze</span></button>
            </div>
        </form>

        <div class="quick">
            <span class="quick-label" id="try-label">Try</span>
            <ul class="chips" aria-labelledby="try-label">
                <?php foreach ($examples as $name): ?><?= profile_chip($name) ?><?php endforeach; ?>
            </ul>
        </div>
        <?php if ($recent !== []): ?>
            <div class="quick">
                <span class="quick-label" id="recent-label">Recent</span>
                <ul class="chips" aria-labelledby="recent-label">
                    <?php foreach ($recent as $name): ?><?= profile_chip($name) ?><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>

    <figure class="panel line-map">
        <svg viewBox="0 0 480 282" role="img" aria-labelledby="map-title map-caption">
            <title id="map-title">How RepoScope works</title>
            <path class="map-line map-line-p" d="M24 40 H120 L210 130 H220" />
            <path class="map-line map-line-c" d="M24 150 H220" />
            <path class="map-line map-line-a" d="M24 260 H120 L210 170 H220" />
            <path class="map-trunk" d="M220 150 H445" />

            <g class="map-bullet line-1">
                <circle cx="24" cy="40" r="18" /><text x="24" y="40">P</text>
            </g>
            <g class="map-bullet line-2">
                <circle cx="24" cy="150" r="18" /><text x="24" y="150">C</text>
            </g>
            <g class="map-bullet line-3">
                <circle cx="24" cy="260" r="18" /><text x="24" y="260">A</text>
            </g>
            <text class="map-label" x="52" y="24">GitHub user</text>
            <text class="map-label" x="52" y="134">Usernames CSV</text>
            <text class="map-label" x="52" y="244">Any CSV</text>

            <rect class="map-interchange" x="206" y="114" width="28" height="72" rx="14" />
            <text class="map-label map-label-strong" x="244" y="104">One table</text>

            <circle class="map-station" cx="285" cy="150" r="9" />
            <circle class="map-station" cx="365" cy="150" r="9" />
            <circle class="map-station" cx="445" cy="150" r="9" />
            <text class="map-label" x="285" y="186" text-anchor="middle">Statistics</text>
            <text class="map-label" x="365" y="128" text-anchor="middle">Charts</text>
            <text class="map-label" x="458" y="186" text-anchor="end">CSV export</text>
        </svg>
        <figcaption class="map-caption" id="map-caption">
            Every source becomes one table of headers and rows, so the same statistics, charts and CSV export work for all three.
        </figcaption>
    </figure>
</div>

<section class="section" aria-labelledby="modes-title">
    <h2 class="section-title" id="modes-title">Modes</h2>
    <ul class="routes">
        <?php foreach ($routes as [$line, $letter, $slot, $title, $text, $href]): ?>
            <li class="route" data-line="<?= e($line) ?>">
                <span class="bullet bullet-lg line-<?= $slot + 1 ?>" aria-hidden="true"><?= e($letter) ?></span>
                <div>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($text) ?></p>
                </div>
                <a class="t-learn" href="<?= e($href) ?>">Open <?= e($title) ?>
                    <span class="t-learn-chevron"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path class="t-learn-arm t-learn-arm-top" d="M6 4L10 8" />
                            <path class="t-learn-arm t-learn-arm-bot" d="M10 8L6 12" />
                        </svg></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
render_footer();
