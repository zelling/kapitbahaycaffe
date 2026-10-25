<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
// Restrict analytics and all subsequent queries to the owner.
require_role('owner');
require_once __DIR__ . '/../includes/analytics.php';

// Load the selected range and analytics for the first server-rendered view.
$rangeInput = $_GET;
if (!isset($rangeInput['range'])) {
    $rangeInput['range'] = '30days';
}

$error = '';
try {
    $range = analytics_date_range($rangeInput);
    $sales = analytics_sales($pdo, $range);
    $products = analytics_products($pdo, $range);
    $ingredients = analytics_ingredient_usage($pdo, $range);
    $ingredients['remaining'] = analytics_remaining_stock($pdo, $range);
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
    $range = analytics_date_range(['range' => '30days']);
    $sales = analytics_sales($pdo, $range);
    $products = analytics_products($pdo, $range);
    $ingredients = analytics_ingredient_usage($pdo, $range);
    $ingredients['remaining'] = analytics_remaining_stock($pdo, $range);
} catch (Throwable $exception) {
    error_log('Analytics dashboard error: ' . $exception->getMessage());
    $error = 'Analytics could not be loaded right now. Please try again.';
    $range = analytics_date_range(['range' => '30days']);
    $sales = [
        'total_items_sold' => 0,
        'number_of_sales' => 0,
        'total_revenue' => 0.0,
        'items' => [],
        'highest_selling_product' => null,
    ];
    $products = ['items' => [], 'fast' => [], 'slow' => [], 'best' => null, 'least' => null];
    $ingredients = ['usage' => [], 'most' => [], 'least' => [], 'remaining' => []];
}

$hasSales = (int) $sales['number_of_sales'] > 0;
$hasIngredientUsage = false;
foreach ($ingredients['usage'] as $ingredient) {
    if ((float) $ingredient['total_usage'] > 0) {
        $hasIngredientUsage = true;
        break;
    }
}

$selectedRange = (string) ($rangeInput['range'] ?? '30days');
$page_title = 'Dashboard';
$theme = 'owner';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-owner-page app-dashboard-page">
    <section class="app-dashboard-header-panel" aria-label="Dashboard date range">
        <div class="menu-heading app-dashboard-heading">
            <div>
                <span class="eyebrow">ANALYTICS</span>
                <h2>Sales at a glance<span>.</span></h2>
                <p class="app-dashboard-range"><?= e($range['label']) ?></p>
            </div>
        </div>
        <?php if ($error !== ''): ?>
            <p class="app-message app-message-error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <div class="app-dashboard-filters">
            <div class="app-dashboard-range-links">
                <a class="app-dashboard-filter<?= $selectedRange === 'today' ? ' app-dashboard-filter-current' : '' ?>" href="<?= e(BASE_URL) ?>/analytics/dashboard.php?range=today">Today</a>
                <a class="app-dashboard-filter<?= $selectedRange === '7days' ? ' app-dashboard-filter-current' : '' ?>" href="<?= e(BASE_URL) ?>/analytics/dashboard.php?range=7days">7 days</a>
                <a class="app-dashboard-filter<?= $selectedRange === '30days' ? ' app-dashboard-filter-current' : '' ?>" href="<?= e(BASE_URL) ?>/analytics/dashboard.php?range=30days">30 days</a>
            </div>
            <form class="app-dashboard-custom-range" method="get">
                <input type="hidden" name="range" value="custom">
                <label>From<input type="date" name="from" required value="<?= e((string) ($_GET['from'] ?? '')) ?>"></label>
                <label>To<input type="date" name="to" required value="<?= e((string) ($_GET['to'] ?? '')) ?>"></label>
                <button class="app-dashboard-filter<?= $selectedRange === 'custom' ? ' app-dashboard-filter-current' : '' ?>" type="submit">Apply</button>
            </form>
        </div>
    </section>

    <section class="app-dashboard-summary" aria-label="Sales summary">
        <article class="app-dashboard-card"><span>Items sold</span><strong><?= (int) $sales['total_items_sold'] ?></strong></article>
        <article class="app-dashboard-card"><span>Revenue</span><strong><?= e(peso((float) $sales['total_revenue'])) ?></strong></article>
        <article class="app-dashboard-card"><span>Sales recorded</span><strong><?= (int) $sales['number_of_sales'] ?></strong></article>
        <article class="app-dashboard-card"><span>Highest-selling</span><strong class="app-dashboard-product-name"><?= e((string) ($sales['highest_selling_product']['name'] ?? 'No sales yet')) ?></strong></article>
    </section>

    <section class="app-dashboard-section" aria-labelledby="performance-heading">
        <div class="app-dashboard-section-heading"><span class="eyebrow">PRODUCT PERFORMANCE</span><h3 id="performance-heading">What is moving<span>.</span></h3></div>
        <?php if (!$hasSales): ?>
            <p class="app-dashboard-empty">No sales were recorded for this range.</p>
        <?php else: ?>
            <div class="app-dashboard-pair-grid">
                <article class="app-dashboard-panel"><h4>Fast-moving</h4><?php if ($products['fast'] === []): ?><p class="app-dashboard-empty">No fast-moving items yet.</p><?php else: ?><ul class="app-dashboard-list"><?php foreach ($products['fast'] as $item): ?><li><span><?= e((string) $item['name']) ?></span><span><span class="app-performance-badge app-performance-fast">Fast</span><?= (int) $item['units'] ?> sold</span></li><?php endforeach; ?></ul><?php endif; ?></article>
                <article class="app-dashboard-panel"><h4>Slow-moving</h4><?php if ($products['slow'] === []): ?><p class="app-dashboard-empty">No slow-moving items yet.</p><?php else: ?><ul class="app-dashboard-list"><?php foreach ($products['slow'] as $item): ?><li><span><?= e((string) $item['name']) ?></span><span><span class="app-performance-badge app-performance-slow">Slow</span><?= (int) $item['units'] ?> sold</span></li><?php endforeach; ?></ul><?php endif; ?></article>
                <article class="app-dashboard-panel app-dashboard-best-least"><h4>Best and least sold</h4><p><span>Best</span><strong><?= e((string) ($products['best']['name'] ?? 'No sales yet')) ?></strong></p><p><span>Least</span><strong><?= e((string) ($products['least']['name'] ?? 'No items yet')) ?></strong></p></article>
            </div>
        <?php endif; ?>
        <div class="app-dashboard-table-wrap"><table class="app-table"><thead><tr><th>Menu item</th><th>Units</th><th>Revenue</th><th>Performance</th></tr></thead><tbody><?php if (!$hasSales): ?><tr><td colspan="4">No sales were recorded for this range.</td></tr><?php else: ?><?php foreach ($products['items'] as $item): ?><tr><td><?= e((string) $item['name']) ?></td><td><?= (int) $item['units'] ?></td><td><?= e(peso((float) $item['revenue'])) ?></td><td><span class="app-performance-badge app-performance-<?= e((string) $item['performance']) ?>"><?= e(ucfirst((string) $item['performance'])) ?></span></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div>
    </section>

    <section class="app-dashboard-section" aria-labelledby="sales-charts-heading">
        <div class="app-dashboard-section-heading"><span class="eyebrow">SALES CHARTS</span><h3 id="sales-charts-heading">The pattern in numbers<span>.</span></h3></div>
        <article class="app-dashboard-panel">
            <h4>Units sold per item</h4>
            <div class="app-chart-box"><canvas id="product-chart" aria-label="Units sold per menu item" role="img"></canvas></div>
            <p id="product-chart-message" class="app-chart-message" hidden></p>
        </article>
    </section>

    <section class="app-dashboard-section" aria-labelledby="ingredients-heading">
        <div class="app-dashboard-section-heading"><span class="eyebrow">INGREDIENT USAGE</span><h3 id="ingredients-heading">What the menu uses<span>.</span></h3></div>
        <div class="app-dashboard-pair-grid">
            <article class="app-dashboard-panel">
                <h4>Most used</h4>
                <?php if (!$hasIngredientUsage): ?>
                    <p class="app-dashboard-empty">No ingredient usage in this range.</p>
                <?php else: ?>
                    <ul class="app-dashboard-list">
                        <?php foreach ($ingredients['most'] as $item): ?>
                            <li>
                                <span><?= e((string) $item['name']) ?></span>
                                <strong><?= e((string) $item['total_usage']) ?><span><?= e((string) $item['unit']) ?></span></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
            <article class="app-dashboard-panel">
                <h4>Least used</h4>
                <?php if (!$hasIngredientUsage): ?>
                    <p class="app-dashboard-empty">No ingredient usage in this range.</p>
                <?php else: ?>
                    <ul class="app-dashboard-list">
                        <?php foreach ($ingredients['least'] as $item): ?>
                            <li>
                                <span><?= e((string) $item['name']) ?></span>
                                <strong><?= e((string) $item['total_usage']) ?><span><?= e((string) $item['unit']) ?></span></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <section class="app-dashboard-section" aria-labelledby="stock-heading">
        <div class="app-dashboard-section-heading"><span class="eyebrow">REMAINING STOCK</span><h3 id="stock-heading">Estimated on hand<span>.</span></h3></div>
        <div class="app-dashboard-table-wrap"><table class="app-table app-stock-table"><thead><tr><th>Ingredient</th><th>Remaining</th><th>Minimum</th><th>Status</th></tr></thead><tbody><?php foreach ($ingredients['remaining'] as $item): ?><tr><td><?= e((string) $item['name']) ?><?php if ($item['negative']): ?><small class="app-negative-note">Check stock count</small><?php endif; ?></td><td><?= e((string) $item['remaining']) ?> <?= e((string) $item['unit']) ?></td><td><?= e((string) $item['min_stock_level']) ?> <?= e((string) $item['unit']) ?></td><td><span class="app-stock-badge app-stock-<?= e((string) $item['status']) ?><?= $item['status'] === 'low' ? ' app-low-badge' : '' ?>"><?= e(ucfirst((string) $item['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div>
        <p class="app-dashboard-note">Remaining stock is an estimate from recorded sales and the recipes entered. It does not depend on the date filter.</p>
    </section>

    <section class="app-dashboard-section" aria-labelledby="ingredient-chart-heading">
        <div class="app-dashboard-section-heading"><span class="eyebrow">INGREDIENT USAGE CHART</span><h3 id="ingredient-chart-heading">Usage by ingredient<span>.</span></h3></div>
        <article class="app-dashboard-panel">
            <div class="app-chart-box"><canvas id="ingredient-chart" aria-label="Ingredient usage" role="img"></canvas></div>
            <p id="ingredient-chart-message" class="app-chart-message" hidden></p>
        </article>
    </section>
</main>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="<?= e(BASE_URL) ?>/assets/js/dashboard.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
