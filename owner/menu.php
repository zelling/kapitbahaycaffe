<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');

function menu_redirect(string $message): never
{
    $_SESSION['flash'] = $message;
    redirect('/owner/menu.php');
}

// Handle all menu mutations before any output.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        http_response_code(400);
        exit('Invalid request.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if (in_array($action, ['archive', 'restore', 'availability'], true)) {
        $field = $action === 'availability' ? 'is_available' : 'is_archived';
        $value = $action === 'availability' ? (int) !empty($_POST['is_available']) : ($action === 'archive' ? 1 : 0);
        $statement = $pdo->prepare("UPDATE menu_items SET $field = :value WHERE id = :id");
        $statement->execute(['value' => $value, 'id' => $id]);
        menu_redirect('Menu item updated.');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($name === '' || $categoryId < 1 || $price === false || $price <= 0) {
        $_SESSION['form_error'] = 'Enter a name, category, and price greater than zero.';
        redirect('/owner/menu.php' . ($id ? '?edit=' . $id : ''));
    }

    $statement = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
    $statement->execute(['id' => $categoryId]);
    if (!$statement->fetch()) {
        $_SESSION['form_error'] = 'Choose an existing category.';
        redirect('/owner/menu.php' . ($id ? '?edit=' . $id : ''));
    }

    $statement = $pdo->prepare('SELECT id FROM menu_items WHERE name = :name AND id != :id');
    $statement->execute(['name' => $name, 'id' => $id]);
    if ($statement->fetch()) {
        $_SESSION['form_error'] = 'A menu item with that name already exists.';
        redirect('/owner/menu.php' . ($id ? '?edit=' . $id : ''));
    }

    $data = [
        'name' => $name,
        'category_id' => $categoryId,
        'price' => $price,
        'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
        'is_available' => !empty($_POST['is_available']),
        'id' => $id,
    ];

    if ($action === 'edit' && $id > 0) {
        $statement = $pdo->prepare('UPDATE menu_items SET category_id = :category_id, name = :name, description = :description, price = :price, is_available = :is_available WHERE id = :id');
        $statement->execute($data);
        menu_redirect('Menu item saved.');
    }

    $statement = $pdo->prepare('INSERT INTO menu_items (category_id, name, description, price, is_available) VALUES (:category_id, :name, :description, :price, :is_available)');
    $statement->execute($data);
    menu_redirect('Menu item added.');
}

// Load the optional edit record and the filtered menu list.
$editingItem = null;
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT * FROM menu_items WHERE id = :id');
    $statement->execute(['id' => (int) $_GET['edit']]);
    $editingItem = $statement->fetch() ?: null;
}

$showArchived = isset($_GET['archived']);
$selectedCategory = (int) ($_GET['category'] ?? 0);
$categories = $pdo->query('SELECT id, name FROM categories WHERE is_archived = 0 ORDER BY name')->fetchAll();
$sql = 'SELECT m.*, c.name AS category_name FROM menu_items m JOIN categories c ON c.id = m.category_id WHERE ' . ($showArchived ? '1 = 1' : 'm.is_archived = 0');
if ($selectedCategory > 0) {
    $sql .= ' AND m.category_id = :category_id';
}
$sql .= ' ORDER BY c.name, m.name';
$statement = $pdo->prepare($sql);
$statement->execute($selectedCategory > 0 ? ['category_id' => $selectedCategory] : []);
$menuItems = $statement->fetchAll();

$page_title = 'Menu';
$theme = 'owner';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-owner-page">
    <div class="menu-heading">
        <div><span class="eyebrow">OWNER TOOLS</span><h2>Menu management<span>.</span></h2></div>
        <a class="app-toggle-link" href="<?= e(BASE_URL) ?>/owner/menu.php<?= $showArchived ? '' : '?archived=1' ?>"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a>
    </div>
    <div class="app-menu-controls"><nav class="category-row" aria-label="Filter menu categories"><a class="category-pill<?= $selectedCategory === 0 ? ' active' : '' ?>" href="<?= e(BASE_URL) ?>/owner/menu.php">Everything</a><?php foreach ($categories as $category): ?><a class="category-pill<?= $selectedCategory === (int) $category['id'] ? ' active' : '' ?>" href="<?= e(BASE_URL) ?>/owner/menu.php?category=<?= (int) $category['id'] ?>"><?= e($category['name']) ?></a><?php endforeach; ?></nav></div>
    <?php if (!empty($_SESSION['flash'])): ?><p class="app-message app-message-success"><?= e((string) $_SESSION['flash']); unset($_SESSION['flash']); ?></p><?php endif; ?>

    <form method="post" class="app-form-card app-menu-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $editingItem ? 'edit' : 'add' ?>"><?php if ($editingItem): ?><input type="hidden" name="id" value="<?= (int) $editingItem['id'] ?>"><?php endif; ?>
        <h2><?= $editingItem ? 'Edit menu item' : 'Add menu item' ?></h2>
        <?php if (!empty($_SESSION['form_error'])): ?><p class="app-form-error"><?= e((string) $_SESSION['form_error']); unset($_SESSION['form_error']); ?></p><?php endif; ?>
        <label class="app-field">Name<input name="name" required value="<?= e($editingItem['name'] ?? '') ?>"></label>
        <label class="app-field">Category<select name="category_id" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) ($editingItem['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
        <label class="app-field">Description<textarea name="description"><?= e($editingItem['description'] ?? '') ?></textarea></label>
        <label class="app-field">Price<input name="price" type="number" min="0.01" step="0.01" required value="<?= e((string) ($editingItem['price'] ?? '')) ?>"></label>
        <label class="milk-choice"><input name="is_available" type="checkbox" value="1" <?= (!$editingItem || $editingItem['is_available']) ? 'checked' : '' ?>> Available for sale</label>
        <button class="primary-button" type="submit">Save menu item</button>
    </form>

    <section class="app-menu-list"><div class="product-grid"><?php foreach ($menuItems as $item): ?><article class="product-card"><div class="product-photo app-menu-photo"<?php if (!empty($item['image_url'])): ?> style="background-image: url('<?= e($item['image_url']) ?>')"<?php endif; ?>><span class="product-tag"><?= e($item['category_name']) ?></span></div><div class="product-body"><div class="product-title-row"><h3><?= e($item['name']) ?></h3><span class="product-price"><?= e(peso($item['price'])) ?></span></div><p><?= e($item['description'] ?? 'No description.') ?></p><div class="product-bottom"><small><?= $item['is_archived'] ? 'Archived' : ($item['is_available'] ? 'Available' : 'Unavailable') ?></small><div class="app-menu-actions"><a class="app-menu-action" href="?edit=<?= (int) $item['id'] ?>">Edit</a><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="availability"><input type="hidden" name="is_available" value="<?= $item['is_available'] ? '0' : '1' ?>"><button class="app-menu-action" type="submit"><?= $item['is_available'] ? 'Unavailable' : 'Available' ?></button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="<?= $item['is_archived'] ? 'restore' : 'archive' ?>"><button class="app-menu-action" type="submit"><?= $item['is_archived'] ? 'Restore' : 'Archive' ?></button></form></div></div></div></article><?php endforeach; ?></div></section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
