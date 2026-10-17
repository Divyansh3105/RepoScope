<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// Served by router.php (php -S) or .htaccess (Apache) for any address that doesn't exist.
http_response_code(404);
session_write_close();

// 'root' => '/' because this page can be shown at any URL depth, where relative links would break
render_header('Page not found', '', [
    'noindex'     => true,
    'root'        => '/',
    'description' => 'This page does not exist. Go back to RepoScope and look up a GitHub user or upload a CSV file.',
]);
?>
<section class="panel not-found">
    <h1>Page not found</h1>
    <p class="muted">There is nothing at this address. Check the link for typos, or start from one of the modes.</p>
    <ul class="chips" aria-label="Pages">
        <li><a class="chip" href="/index.php">Home</a></li>
        <li><a class="chip" href="/profile.php">Profile</a></li>
        <li><a class="chip" href="/compare.php">Compare</a></li>
        <li><a class="chip" href="/analyze.php">Analyze</a></li>
    </ul>
</section>
<?php
render_footer();
