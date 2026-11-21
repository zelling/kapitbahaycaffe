<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
// Restrict the Trends view before producing page output.
require_role('owner');
require_once __DIR__ . '/../includes/gemini.php';

$timezone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $timezone);
$mode = (string) ($_GET['mode'] ?? 'week');
if (!in_array($mode, ['day', 'week', 'month'], true)) {
    $mode = 'week';
}
$anchor = (string) ($_GET['anchor'] ?? $today->format('Y-m-d'));
$geminiAvailable = gemini_enabled();

$page_title = 'Trends';
$theme = 'owner';
require __DIR__ . '/../includes/header.php';
?>
<main
    id="app-trends"
    class="app-owner-page app-dashboard-page app-trends-page"
    data-api-url="<?= e(BASE_URL) ?>/analytics/trends_api.php"
    data-csrf="<?= e(csrf_token()) ?>"
    data-gemini="<?= $geminiAvailable ? '1' : '0' ?>"
    data-mode="<?= e($mode) ?>"
    data-anchor="<?= e($anchor) ?>"
>
    <section class="app-dashboard-header-panel" aria-label="Trends date range">
        <div class="menu-heading app-dashboard-heading">
            <div>
                <span class="eyebrow">OWNER TRENDS</span>
                <h2>What is changing<span>.</span></h2>
                <p class="app-dashboard-range">Compare recorded sales with the previous equal period.</p>
            </div>
        </div>
        <form class="app-trends-controls" method="get">
            <label class="app-trends-control">
                Period
                <select name="mode">
                    <option value="day"<?= $mode === 'day' ? ' selected' : '' ?>>Day</option>
                    <option value="week"<?= $mode === 'week' ? ' selected' : '' ?>>Week</option>
                    <option value="month"<?= $mode === 'month' ? ' selected' : '' ?>>Month</option>
                </select>
            </label>
            <label class="app-trends-control">
                Anchor date
                <input type="date" name="anchor" max="<?= e($today->format('Y-m-d')) ?>" value="<?= e($anchor) ?>" required>
            </label>
            <button class="app-dashboard-filter app-dashboard-filter-current" type="submit">Apply</button>
        </form>
    </section>

    <p id="app-trends-status" class="app-trends-message" aria-live="polite"></p>
    <p id="app-trends-progress" class="app-trends-warning" hidden></p>
    <p id="app-trends-nodata" class="app-trends-message" hidden></p>

    <section id="app-trends-expected" class="app-dashboard-section app-trends-expected" hidden aria-labelledby="app-trends-expected-heading">
        <div class="app-dashboard-section-heading">
            <span class="eyebrow">EXPECTED RANGE</span>
            <h3 id="app-trends-expected-heading">Based on recent recorded periods<span>.</span></h3>
        </div>
        <p></p>
    </section>

    <section class="app-dashboard-section" aria-labelledby="app-trends-items-heading">
        <div class="app-dashboard-section-heading">
            <span class="eyebrow">ITEM TRENDS</span>
            <h3 id="app-trends-items-heading">Menu item movement<span>.</span></h3>
        </div>
        <div id="app-trends-table" class="app-dashboard-table-wrap app-trends-table-wrap">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Units</th>
                        <th>Previous</th>
                        <th>Change</th>
                        <th>Trend</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <section class="app-dashboard-section" aria-labelledby="app-trends-charts-heading">
        <div class="app-dashboard-section-heading">
            <span class="eyebrow">CHARTS</span>
            <h3 id="app-trends-charts-heading">Patterns in the numbers<span>.</span></h3>
        </div>
        <div class="app-trends-chart-grid">
            <article class="app-dashboard-panel">
                <h4>Daily units</h4>
                <div class="app-trends-chart-box"><canvas id="app-chart-daily" aria-label="Daily units and moving average" role="img"></canvas></div>
            </article>
            <article class="app-dashboard-panel">
                <h4>Item comparison</h4>
                <div class="app-trends-chart-box"><canvas id="app-chart-items" aria-label="Menu item trend comparison" role="img"></canvas></div>
            </article>
            <article class="app-dashboard-panel">
                <h4>Ingredient usage</h4>
                <div class="app-trends-chart-box"><canvas id="app-chart-ingredients" aria-label="Ingredient usage comparison" role="img"></canvas></div>
            </article>
            <article class="app-dashboard-panel">
                <h4>Average by weekday</h4>
                <div class="app-trends-chart-box"><canvas id="app-chart-weekday" aria-label="Average units by weekday" role="img"></canvas></div>
            </article>
        </div>
    </section>

    <?php if ($geminiAvailable): ?>
        <section id="app-trends-insights" class="app-dashboard-section app-trends-insights" aria-labelledby="app-trends-insights-heading">
            <div class="app-dashboard-section-heading">
                <span class="eyebrow">OPTIONAL INSIGHTS</span>
                <h3 id="app-trends-insights-heading">AI summary<span>.</span></h3>
            </div>
            <button id="app-insights-button" class="app-dashboard-filter app-dashboard-filter-current" type="button">Generate insights</button>
            <div id="app-insights-output" aria-live="polite"></div>
            <p class="app-dashboard-note">AI-generated summary. Check it against the charts.</p>
        </section>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
<script src="<?= e(BASE_URL) ?>/assets/js/trends.js" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
