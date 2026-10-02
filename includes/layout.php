<?php
declare(strict_types=1);

/*
 * Page layout helpers. A page looks like:
 *
 *     render_header('Profile', 'profile');
 *     ... the page's own HTML ...
 *     render_footer();
 *
 * Inside these functions we close the PHP tag (?>) to write plain HTML and reopen
 * it (<?php) when we need PHP again. The HTML is printed when the function runs.
 */

/** Prints the top of every page: <head>, the site header and the navigation. */
function render_header(string $title, string $active = ''): void
{
    // nav key => [link, label]. Links are relative, so the site also works from a sub-folder.
    $nav = [
        'home'    => ['index.php', 'Home'],
        'profile' => ['profile.php', 'Profile'],
        'compare' => ['compare.php', 'Compare'],
        'analyze' => ['analyze.php', 'Analyze'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · RepoScope</title>
    <!-- Declaring an icon stops browsers requesting /favicon.ico, which `php -S` would answer by running index.php. -->
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="index.php">
            <svg class="brand-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="10.5" cy="10.5" r="7"/>
                <path d="M15.5 15.5 21 21M7.5 13v-1.5M10.5 13V8M13.5 13v-3"/>
            </svg>
            RepoScope
        </a>
        <nav aria-label="Main">
            <ul class="nav">
                <?php foreach ($nav as $key => [$href, $label]): ?>
                    <li><a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</header>
<main id="main" class="container">
    <?php
}

/** Prints the bottom of every page. */
function render_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <div class="container">RepoScope · GitHub &amp; CSV analytics built with plain PHP and HTML5 Canvas</div>
</footer>
</body>
</html>
    <?php
}
