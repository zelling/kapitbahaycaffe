<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');

// Store a success message and return to the recipe list.
function recipe_redirect(string $message): never
{
    $_SESSION['flash'] = $message;
    redirect('/owner/recipes.php');
}

// Handle recipe creation, editing, and removal before producing page output.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        http_response_code(400);
        exit('Invalid request.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    // Reject tampered actions before they can fall through to an insert.
    if (!in_array($action, ['add', 'edit', 'delete'], true)) {
        http_response_code(400);
        exit('Invalid request.');
    }

    if ($action === 'delete') {
        // Recipe rows are not sales records; usage estimates use the current recipe components.
        $statement = $pdo->prepare('DELETE FROM recipes WHERE id = :id');
        $statement->execute(['id' => $id]);
        recipe_redirect('Recipe ingredient removed.');
    }

    $menuItemId = (int) ($_POST['menu_item_id'] ?? 0);
    $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);
    $quantity = filter_var($_POST['qty_per_item'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($menuItemId < 1 || $ingredientId < 1 || $quantity === false || $quantity <= 0) {
        $_SESSION['form_error'] = 'Choose a menu item and ingredient, then enter a quantity greater than zero.';
        redirect('/owner/recipes.php' . ($id ? '?edit=' . $id : ''));
    }

    // Accept only current menu items and ingredients, never archived records.
    $statement = $pdo->prepare('SELECT id FROM menu_items WHERE id = :id AND is_archived = 0');
    $statement->execute(['id' => $menuItemId]);
    $menuItemExists = (bool) $statement->fetch();

    $statement = $pdo->prepare('SELECT id FROM ingredients WHERE id = :id AND is_archived = 0');
    $statement->execute(['id' => $ingredientId]);
    $ingredientExists = (bool) $statement->fetch();
    if (!$menuItemExists || !$ingredientExists) {
        $_SESSION['form_error'] = 'Choose existing records.';
        redirect('/owner/recipes.php' . ($id ? '?edit=' . $id : ''));
    }

    // Prevent the same ingredient from appearing twice in one recipe.
    $statement = $pdo->prepare(
        'SELECT id FROM recipes WHERE menu_item_id = :menu_item_id AND ingredient_id = :ingredient_id AND id != :id'
    );
    $statement->execute([
        'menu_item_id' => $menuItemId,
        'ingredient_id' => $ingredientId,
        'id' => $id,
    ]);
    if ($statement->fetch()) {
        $_SESSION['form_error'] = 'This ingredient is already in that recipe.';
        redirect('/owner/recipes.php' . ($id ? '?edit=' . $id : ''));
    }

    if ($action === 'edit' && $id) {
        $statement = $pdo->prepare(
            'UPDATE recipes SET menu_item_id = :menu_item_id, ingredient_id = :ingredient_id, qty_per_item = :quantity WHERE id = :id'
        );
        $statement->execute([
            'menu_item_id' => $menuItemId,
            'ingredient_id' => $ingredientId,
            'quantity' => $quantity,
            'id' => $id,
        ]);
        recipe_redirect('Recipe updated.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO recipes (menu_item_id, ingredient_id, qty_per_item) VALUES (:menu_item_id, :ingredient_id, :quantity)'
    );
    $statement->execute([
        'menu_item_id' => $menuItemId,
        'ingredient_id' => $ingredientId,
        'quantity' => $quantity,
    ]);
    recipe_redirect('Recipe ingredient added.');
}

// Load the optional edit record and the current recipe choices.
$edit = null;
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT * FROM recipes WHERE id = :id');
    $statement->execute(['id' => (int) $_GET['edit']]);
    $edit = $statement->fetch() ?: null;
}

$menus = $pdo->query('SELECT id, name FROM menu_items WHERE is_archived = 0 ORDER BY name')->fetchAll();
$ingredients = $pdo->query('SELECT id, name, unit FROM ingredients WHERE is_archived = 0 ORDER BY name')->fetchAll();
$rows = $pdo->query(
    'SELECT r.*, m.name AS menu_name, i.name AS ingredient_name, i.unit
     FROM recipes r
     JOIN menu_items m ON m.id = r.menu_item_id
     JOIN ingredients i ON i.id = r.ingredient_id
     ORDER BY m.name, i.name'
)->fetchAll();

$page_title = 'Recipes';
$theme = 'owner';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-owner-page">
    <div class="app-page-heading">
        <div>
            <p class="eyebrow">Owner tools</p>
            <h1>Recipes<span>.</span></h1>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash'])): ?>
        <p class="app-message app-message-success">
            <?= e((string) $_SESSION['flash']); unset($_SESSION['flash']); ?>
        </p>
    <?php endif; ?>

    <section class="app-admin-grid">
        <form method="post" class="app-form-card">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
            <?php if ($edit): ?>
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
            <?php endif; ?>

            <h2><?= $edit ? 'Edit recipe row' : 'Add recipe row' ?></h2>

            <?php if (!empty($_SESSION['form_error'])): ?>
                <p class="app-form-error">
                    <?= e((string) $_SESSION['form_error']); unset($_SESSION['form_error']); ?>
                </p>
            <?php endif; ?>

            <label class="app-field">Menu item
                <select name="menu_item_id" required>
                    <option value="">Choose item</option>
                    <?php foreach ($menus as $menu): ?>
                        <option value="<?= (int) $menu['id'] ?>" <?= (int) ($edit['menu_item_id'] ?? 0) === (int) $menu['id'] ? 'selected' : '' ?>>
                            <?= e((string) $menu['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="app-field">Ingredient
                <select name="ingredient_id" required>
                    <option value="">Choose ingredient</option>
                    <?php foreach ($ingredients as $ingredient): ?>
                        <option value="<?= (int) $ingredient['id'] ?>" <?= (int) ($edit['ingredient_id'] ?? 0) === (int) $ingredient['id'] ? 'selected' : '' ?>>
                            <?= e((string) $ingredient['name'] . ' (' . (string) $ingredient['unit'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="app-field">Quantity per item
                <input name="qty_per_item" type="number" min="0.001" step="0.001" required value="<?= e((string) ($edit['qty_per_item'] ?? '')) ?>">
            </label>

            <button class="primary-button">Save recipe row</button>
        </form>

        <section class="app-list-card">
            <div class="app-table-wrap">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Menu item</th>
                            <th>Ingredient</th>
                            <th>Quantity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e((string) $row['menu_name']) ?></td>
                                <td>
                                    <?= e((string) $row['ingredient_name']) ?>
                                    <small><?= e((string) $row['unit']) ?></small>
                                </td>
                                <td><?= e((string) $row['qty_per_item']) ?></td>
                                <td>
                                    <a class="app-table-link" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                                    <form method="post" class="app-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="app-table-link">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
