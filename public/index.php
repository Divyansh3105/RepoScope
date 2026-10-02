<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// PHP locks the session file while a request has it open, so other tabs wait.
// This page never writes to the session, so release the lock straight away.
session_write_close();

render_header('Home', 'home');
?>
<section class="hero">
    <p class="eyebrow">GitHub &amp; CSV analytics</p>
    <h1>See the story in your data.</h1>
    <p class="lead">RepoScope turns GitHub profiles and CSV files into statistics and interactive charts. Pick a mode to start.</p>
</section>

<section class="grid" aria-label="Modes">
    <article class="card mode-card">
        <span class="icon-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>
            </svg>
        </span>
        <h2>Profile</h2>
        <p>Look up one GitHub user: their languages, most-starred repositories and how their work grew over time.</p>
        <!-- GET, not POST: a lookup changes nothing, so the result URL can be bookmarked and shared. -->
        <form action="profile.php" method="get">
            <label for="user">GitHub username</label>
            <div class="input-row">
                <input id="user" name="user" type="text" required maxlength="39"
                       placeholder="e.g. torvalds" autocomplete="off" autocapitalize="off" spellcheck="false">
                <button class="btn" type="submit">Analyze</button>
            </div>
        </form>
    </article>

    <article class="card mode-card">
        <span class="icon-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M5 21V11M12 21V4M19 21v-6"/>
            </svg>
        </span>
        <h2>Compare</h2>
        <p>Upload a CSV with up to 15 GitHub usernames to get a leaderboard and charts for the whole group.</p>
        <a class="btn btn-secondary" href="compare.php">Open Compare</a>
    </article>

    <article class="card mode-card">
        <span class="icon-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M10 4v16"/>
            </svg>
        </span>
        <h2>Analyze</h2>
        <p>Upload any CSV. RepoScope works out which columns hold numbers or text, summarises them and charts the columns you pick.</p>
        <a class="btn btn-secondary" href="analyze.php">Open Analyze</a>
    </article>
</section>

<section class="card">
    <h2>How it works</h2>
    <ol class="steps">
        <li><strong>Collect</strong>GitHub API responses and CSV uploads are converted into one table shape: a list of headers plus rows.</li>
        <li><strong>Analyze</strong>The same statistics functions run on any table: counts, sums, averages, medians and top values.</li>
        <li><strong>Visualize &amp; export</strong>Charts are drawn on HTML5 canvas, and every result table can be downloaded as a CSV file.</li>
    </ol>
</section>
<?php
render_footer();
