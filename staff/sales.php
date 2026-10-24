<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

function sales_redirect(string $message, string $type = 'success'): never
{
    $_SESSION['sales_message'] = $message;
    $_SESSION['sales_message_type'] = $type;
    redirect('/staff/sales.php');
}

// Handle sale recording before producing page output.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        http_response_code(400);
        exit('Invalid request. Please return to the sales page and try again.');
    }

    $submittedItems = $_POST['items'] ?? null;
    if (!is_array($submittedItems) || $submittedItems === []) {
        sales_redirect('Add at least one item before recording a sale.', 'error');
    }

    $items = [];
    foreach ($submittedItems as $menuItemId => $quantity) {
        $menuItemIdString = (string) $menuItemId;
        $quantityString = is_string($quantity) || is_int($quantity) ? (string) $quantity : '';
        if (!preg_match('/^[1-9][0-9]*$/', $menuItemIdString)) {
            sales_redirect('The sale items are invalid.', 'error');
        }
        if (!preg_match('/^(?:[1-9]|[1-9][0-9])$/', $quantityString)) {
            sales_redirect('Each quantity must be between 1 and 99.', 'error');
        }

        $id = (int) $menuItemIdString;
        $items[$id] = ($items[$id] ?? 0) + (int) $quantityString;
        if ($items[$id] > 99) {
            sales_redirect('Each quantity must be between 1 and 99.', 'error');
        }
    }

    try {
        $pdo->beginTransaction();
        $itemStatement = $pdo->prepare(
            'SELECT m.id, m.price
             FROM menu_items m
             INNER JOIN categories c ON c.id = m.category_id
             WHERE m.id = :id
               AND m.is_available = 1
               AND m.is_archived = 0
               AND c.is_archived = 0
             FOR UPDATE'
        );
        $saleItems = [];
        $totalCentavos = 0;

        foreach ($items as $id => $quantity) {
            $itemStatement->execute(['id' => $id]);
            $item = $itemStatement->fetch();
            if (!$item) {
                throw new RuntimeException('One or more menu items are no longer available. Please review the sale and try again.');
            }

            $unitPriceCentavos = (int) round((float) $item['price'] * 100);
            $totalCentavos += $unitPriceCentavos * $quantity;
            $saleItems[] = ['menu_item_id' => (int) $item['id'], 'quantity' => $quantity, 'unit_price' => (string) $item['price']];
        }

        $totalAmount = number_format($totalCentavos / 100, 2, '.', '');
        $saleStatement = $pdo->prepare('INSERT INTO sales (user_id, total_amount, sold_at) VALUES (:user_id, :total_amount, NOW())');
        $saleStatement->execute(['user_id' => (int) $_SESSION['user_id'], 'total_amount' => $totalAmount]);
        $saleId = (int) $pdo->lastInsertId();
        $saleItemStatement = $pdo->prepare('INSERT INTO sale_items (sale_id, menu_item_id, quantity, unit_price) VALUES (:sale_id, :menu_item_id, :quantity, :unit_price)');

        foreach ($saleItems as $saleItem) {
            $saleItemStatement->execute(['sale_id' => $saleId, 'menu_item_id' => $saleItem['menu_item_id'], 'quantity' => $saleItem['quantity'], 'unit_price' => $saleItem['unit_price']]);
        }

        $pdo->commit();
        sales_redirect('Sale recorded. Total: ' . peso($totalAmount));
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sales_redirect($exception->getMessage(), 'error');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sales_redirect('We could not record that sale. Please try again.', 'error');
    }
}

// Load only saleable menu items and active categories.
$statement = $pdo->query('SELECT m.id, m.name, m.description, m.price, m.image_url, c.id AS category_id, c.name AS category_name FROM menu_items m INNER JOIN categories c ON c.id = m.category_id WHERE m.is_available = 1 AND m.is_archived = 0 AND c.is_archived = 0 ORDER BY c.name, m.name');
$menuItems = $statement->fetchAll();
$categories = [];
foreach ($menuItems as $menuItem) {
    $categories[(int) $menuItem['category_id']] = (string) $menuItem['category_name'];
}

$page_title = 'Record sale';
$theme = 'owner';
require __DIR__ . '/../includes/header.php';
$message = (string) ($_SESSION['sales_message'] ?? '');
$messageType = (string) ($_SESSION['sales_message_type'] ?? 'success');
unset($_SESSION['sales_message'], $_SESSION['sales_message_type']);
?>
<main class="app-owner-page app-sales-page">
    <div class="menu-heading">
        <div><span class="eyebrow">SALES LOG</span><h2>Record a sale<span>.</span></h2></div>
    </div>
    <?php if ($message !== ''): ?>
        <p class="app-message <?= $messageType === 'error' ? 'app-message-error' : 'app-message-success' ?>" role="alert"><?= e($message) ?></p>
    <?php endif; ?>
    <div class="app-sales-layout">
        <section class="app-sales-menu" aria-labelledby="sale-menu-heading">
            <h3 id="sale-menu-heading" class="app-visually-hidden">Available menu items</h3>
            <nav class="category-row" aria-label="Filter menu categories">
                <button class="category-pill active" type="button" data-category-filter="all" aria-pressed="true">Everything</button>
                <?php foreach ($categories as $categoryId => $categoryName): ?>
                    <button class="category-pill" type="button" data-category-filter="<?= (int) $categoryId ?>" aria-pressed="false"><?= e($categoryName) ?></button>
                <?php endforeach; ?>
            </nav>
            <div class="product-grid" id="sale-menu-grid">
                <?php foreach ($menuItems as $menuItem): ?>
                    <article class="product-card" data-category-id="<?= (int) $menuItem['category_id'] ?>">
                        <div class="product-photo app-sales-photo"<?php if (!empty($menuItem['image_url'])): ?> style="background-image: url('<?= e((string) $menuItem['image_url']) ?>')"<?php endif; ?>><span class="product-tag"><?= e((string) $menuItem['category_name']) ?></span></div>
                        <div class="product-body">
                            <div class="product-title-row"><h3><?= e((string) $menuItem['name']) ?></h3><span class="product-price"><?= e(peso((string) $menuItem['price'])) ?></span></div>
                            <p><?= e((string) ($menuItem['description'] ?: 'Made fresh for the sale log.')) ?></p>
                            <div class="product-bottom"><small>Available</small><button class="add-button" type="button" data-add-item data-item-id="<?= (int) $menuItem['id'] ?>" data-item-name="<?= e((string) $menuItem['name']) ?>" data-item-price="<?= e((string) $menuItem['price']) ?>">Add</button></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($menuItems === []): ?><p class="app-sales-empty-menu">No menu items are available to record.</p><?php endif; ?>
        </section>
        <aside class="app-sale-panel" aria-labelledby="sale-list-heading">
            <div class="app-sale-panel-heading"><div><span class="eyebrow">CURRENT SALE</span><h3 id="sale-list-heading">Selected items<span>.</span></h3></div></div>
            <form method="post" id="sale-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div id="sale-items" class="app-sale-items" aria-live="polite"></div>
                <p id="sale-empty" class="app-sale-empty">Choose menu items to start a sale.</p>
                <div id="sale-hidden-items"></div>
                <div class="app-sale-total"><span>Total</span><strong id="sale-total"><?= e(peso('0')) ?></strong></div>
                <button id="record-sale-button" class="primary-button app-record-sale-button" type="submit" disabled>Record sale</button>
            </form>
        </aside>
    </div>
</main>
<script src="<?= e(BASE_URL) ?>/assets/js/sales.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
