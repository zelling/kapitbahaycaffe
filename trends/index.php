<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
// Trends and optional AI insights are available to the owner only.
require_role('owner');
require_once __DIR__ . '/../includes/analytics.php';
require_once __DIR__ . '/../includes/trends.php';

$error = '';
$summaryMessage = '';
$summary = '';
try {
    $period = trends_period($_GET);
    $trendData = trends_data($pdo, $period);

    // Generate an optional summary only after an explicit protected request.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'generate_summary') {
            throw new InvalidArgumentException('The requested action is not valid.');
        }
        if (!verify_csrf()) {
            throw new InvalidArgumentException('Your form has expired. Please try again.');
        }
        $gemini = trends_gemini_summary($pdo, $trendData);
        if (isset($gemini['summary'])) {
            $summary = (string) $gemini['summary'];
        } else {
            $summaryMessage = (string) ($gemini['message'] ?? 'The AI summary is unavailable right now.');
        }
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
    $period = trends_period([]);
    $trendData = trends_data($pdo, $period);
} catch (Throwable $exception) {
    error_log('Trends page error: ' . $exception->getMessage());
    $error = 'Trends could not be loaded right now. Please try again.';
    $period = trends_period([]);
    $trendData = ['period' => $period, 'first_sale' => null, 'comparison_available' => false, 'expected_available' => false, 'products' => [], 'ingredients' => [], 'daily' => [], 'weekday_averages' => []];
}

$page_title = 'Trends';
$theme = 'staff';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-owner-page app-trends-page">
    <section class="app-dashboard-header-panel">
        <div class="app-dashboard-heading">
            <span class="eyebrow">OWNER TRENDS</span>
            <h2>What is trending<span>.</span></h2>
            <p class="app-dashboard-range"><?= e((string) $trendData['period']['label']) ?></p>
        </div>
        <?php if ($error !== ''): ?><p class="app-message app-message-error" role="alert"><?= e($error) ?></p><?php endif; ?>
        <form class="app-trends-filter" method="get">
            <label class="app-field">Period<select name="period"><option value="day"<?= $period['mode'] === 'day' ? ' selected' : '' ?>>Day</option><option value="week"<?= $period['mode'] === 'week' ? ' selected' : '' ?>>Week</option><option value="month"<?= $period['mode'] === 'month' ? ' selected' : '' ?>>Month</option></select></label>
            <label class="app-field">Anchor date<input type="date" name="anchor" value="<?= e((string) $period['anchor']) ?>" required></label>
            <button class="app-dashboard-filter app-dashboard-filter-current" type="submit">View trends</button>
        </form>
        <?php if (!$trendData['comparison_available']): ?><p class="app-message">Not enough data for this comparison.</p><?php endif; ?>
    </section>

    <section class="app-dashboard-section">
        <div class="app-dashboard-section-heading"><span class="eyebrow">ITEM TRENDS</span><h3>Items moving now<span>.</span></h3></div>
        <div class="app-dashboard-table-wrap"><table class="app-table"><thead><tr><th>Menu item</th><th>Units now</th><th>Units before</th><th>Change</th><th>Trend</th><th>Expected range</th></tr></thead><tbody>
        <?php if ($trendData['products'] === []): ?><tr><td colspan="6">No menu items are available.</td></tr><?php endif; ?>
        <?php foreach ($trendData['products'] as $item): ?><tr><td><?= e((string) $item['name']) ?></td><td><?= (int) $item['current'] ?></td><td><?= (int) $item['previous'] ?></td><td><?= $item['percent_change'] === null ? '—' : e((string) $item['percent_change']) . '%' ?></td><td><span class="app-trend-badge app-trend-<?= e((string) $item['label']) ?>"><?= e(ucfirst((string) $item['label'])) ?></span></td><td><?= e((string) $item['expected']['text']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="app-dashboard-note">Estimate from past averages. Not a forecast.</p>
    </section>

    <section class="app-dashboard-section">
        <div class="app-dashboard-section-heading"><span class="eyebrow">INGREDIENT TRENDS</span><h3>Estimated menu usage<span>.</span></h3></div>
        <div class="app-dashboard-table-wrap"><table class="app-table"><thead><tr><th>Ingredient</th><th>Usage now</th><th>Usage before</th><th>Change</th><th>Trend</th></tr></thead><tbody>
        <?php if ($trendData['ingredients'] === []): ?><tr><td colspan="5">No ingredients are available.</td></tr><?php endif; ?>
        <?php foreach ($trendData['ingredients'] as $item): ?><tr><td><?= e((string) $item['name']) ?></td><td><?= e((string) $item['current']) ?> <?= e((string) $item['unit']) ?></td><td><?= e((string) $item['previous']) ?> <?= e((string) $item['unit']) ?></td><td><?= $item['percent_change'] === null ? '—' : e((string) $item['percent_change']) . '%' ?></td><td><span class="app-trend-badge app-trend-<?= e((string) $item['label']) ?>"><?= e(ucfirst((string) $item['label'])) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

    <section class="app-dashboard-section">
        <div class="app-dashboard-section-heading"><span class="eyebrow">CHARTS</span><h3>Patterns from recorded sales<span>.</span></h3></div>
        <div class="app-trends-chart-grid">
            <article class="app-dashboard-panel"><h4>Daily units (last 30 days)</h4><div class="app-chart-box"><canvas id="trends-daily-chart" role="img" aria-label="Daily units and seven day moving average"></canvas></div></article>
            <article class="app-dashboard-panel"><h4>Rising and falling items</h4><div class="app-chart-box"><canvas id="trends-items-chart" role="img" aria-label="Trending item percentage changes"></canvas></div></article>
            <article class="app-dashboard-panel"><h4>Ingredient usage comparison</h4><div class="app-chart-box"><canvas id="trends-ingredients-chart" role="img" aria-label="Ingredient usage this period and previous period"></canvas></div></article>
            <article class="app-dashboard-panel"><h4>Average units by day of week</h4><div class="app-chart-box"><canvas id="trends-weekday-chart" role="img" aria-label="Average units by day of week"></canvas></div></article>
        </div>
    </section>

    <section class="app-dashboard-section">
        <div class="app-dashboard-section-heading"><span class="eyebrow">OPTIONAL AI SUMMARY</span><h3>Gemini summary<span>.</span></h3></div>
        <article class="app-dashboard-panel app-trends-summary">
            <p>This optional summary describes the displayed aggregate figures. Check it against the charts.</p>
            <?php if ($summary !== ''): ?><p class="app-trends-summary-text"><?= nl2br(e($summary)) ?></p><?php elseif ($summaryMessage !== ''): ?><p class="app-message app-message-error" role="alert"><?= e($summaryMessage) ?></p><?php else: ?><form method="post" action="<?= e(BASE_URL) ?>/trends/index.php?<?= e(http_build_query(['period' => $period['mode'], 'anchor' => $period['anchor']])) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="generate_summary"><button class="app-signout-button" type="submit">Generate AI summary</button></form><?php endif; ?>
        </article>
    </section>
</main>
<script>window.kapetbahayTrends = <?= json_encode(['products' => $trendData['products'], 'ingredients' => $trendData['ingredients'], 'daily' => $trendData['daily'], 'weekday_averages' => $trendData['weekday_averages']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="<?= e(BASE_URL) ?>/assets/js/trends.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
