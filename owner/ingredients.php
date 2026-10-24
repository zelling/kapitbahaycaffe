<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('owner');

// Store a success message and return to the ingredient list.
function ingredient_go(string $message): never
{
    $_SESSION['flash'] = $message;
    redirect('/owner/ingredients.php');
}

// Handle ingredient creation, editing, archiving, and restoration before output.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        http_response_code(400);
        exit('Invalid request.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    // Archive or restore: cast the flag to int so MySQL never receives ''.
    if (in_array($action, ['archive', 'restore'], true)) {
        $statement = $pdo->prepare('UPDATE ingredients SET is_archived = :archived WHERE id = :id');
        $statement->execute(['archived' => (int) ($action === 'archive'), 'id' => $id]);
        ingredient_go($action === 'archive' ? 'Ingredient archived.' : 'Ingredient restored.');
    }

    // Only add and edit are valid from here on.
    if (!in_array($action, ['add', 'edit'], true)) {
        http_response_code(400);
        exit('Invalid request.');
    }

    // Validate the form fields.
    $name = trim((string) ($_POST['name'] ?? ''));
    $unit = trim((string) ($_POST['unit'] ?? ''));
    $stock = filter_var($_POST['stock_qty'] ?? null, FILTER_VALIDATE_FLOAT);
    $minimum = filter_var($_POST['min_stock_level'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($name === '' || $unit === '' || $stock === false || $minimum === false || $stock < 0 || $minimum < 0) {
        $_SESSION['form_error'] = 'Enter a name and unit, with stock and minimum levels of zero or more.';
        redirect('/owner/ingredients.php' . ($id ? '?edit=' . $id : ''));
    }

    // Reject a name that another ingredient already uses.
    $statement = $pdo->prepare('SELECT id FROM ingredients WHERE name = :name AND id != :id');
    $statement->execute(['name' => $name, 'id' => $id]);
    if ($statement->fetch()) {
        $_SESSION['form_error'] = 'An ingredient with that name already exists.';
        redirect('/owner/ingredients.php' . ($id ? '?edit=' . $id : ''));
    }

    // Values shared by add and edit.
    $values = ['name' => $name, 'unit' => $unit, 'stock' => $stock, 'minimum' => $minimum];

    // Edit: update the row; reset the stock timestamp only if the quantity changed.
    if ($action === 'edit' && $id) {
        $statement = $pdo->prepare('SELECT stock_qty FROM ingredients WHERE id = :id');
        $statement->execute(['id' => $id]);
        $current = $statement->fetch();
        $stock_changed = !$current || (float) $current['stock_qty'] !== (float) $stock;
        $timestamp_sql = $stock_changed ? ', stock_updated_at = NOW()' : '';

        $statement = $pdo->prepare(
            'UPDATE ingredients SET name = :name, unit = :unit, stock_qty = :stock, min_stock_level = :minimum'
            . $timestamp_sql . ' WHERE id = :id'
        );
        $statement->execute($values + ['id' => $id]);
        ingredient_go('Ingredient updated.');
    }

    // Add: the INSERT has no :id placeholder, so do not pass an id.
    $statement = $pdo->prepare('INSERT INTO ingredients (name, unit, stock_qty, min_stock_level) VALUES (:name, :unit, :stock, :minimum)');
    $statement->execute($values);
    ingredient_go('Ingredient added.');
}

// Load the optional edit record and requested ingredient list.
$editing = null;
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT * FROM ingredients WHERE id = :id');
    $statement->execute(['id' => (int) $_GET['edit']]);
    $editing = $statement->fetch() ?: null;
}
$archived = isset($_GET['archived']);
$rows = $pdo->query('SELECT * FROM ingredients ' . ($archived ? '' : 'WHERE is_archived = 0 ') . 'ORDER BY name')->fetchAll();
$page_title = 'Ingredients';
$theme = 'owner';
require __DIR__ . '/../includes/header.php';
?>
<main class="app-owner-page">
    <div class="app-page-heading">
        <div>
            <p class="eyebrow">Owner tools</p>
            <h1>Ingredients<span>.</span></h1>
        </div>
        <a class="app-toggle-link" href="<?= e(BASE_URL) ?>/owner/ingredients.php<?= $archived ? '' : '?archived=1' ?>">
            <?= $archived ? 'Hide archived' : 'Show archived' ?>
        </a>
    </div>

    <?php if (!empty($_SESSION['flash'])): ?>
        <p class="app-message app-message-success"><?= e((string) $_SESSION['flash']); unset($_SESSION['flash']); ?></p>
    <?php endif; ?>

    <section class="app-admin-grid">
        <!-- Add / edit form -->
        <form method="post" class="app-form-card">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
            <?php endif; ?>

            <h2><?= $editing ? 'Edit ingredient' : 'Add ingredient' ?></h2>

            <?php if (!empty($_SESSION['form_error'])): ?>
                <p class="app-form-error"><?= e((string) $_SESSION['form_error']); unset($_SESSION['form_error']); ?></p>
            <?php endif; ?>

            <label class="app-field">Name
                <input name="name" required maxlength="150" value="<?= e($editing['name'] ?? '') ?>">
            </label>
            <label class="app-field">Unit
                <input name="unit" required maxlength="30" value="<?= e($editing['unit'] ?? '') ?>">
            </label>
            <label class="app-field">Stock quantity
                <input name="stock_qty" type="number" min="0" step="0.001" required value="<?= e((string) ($editing['stock_qty'] ?? '')) ?>">
            </label>
            <label class="app-field">Minimum stock level
                <input name="min_stock_level" type="number" min="0" step="0.001" required value="<?= e((string) ($editing['min_stock_level'] ?? '')) ?>">
            </label>

            <button class="primary-button" type="submit"><?= $editing ? 'Save changes' : 'Add ingredient' ?></button>
            <?php if ($editing): ?>
                <a class="app-cancel-link" href="<?= e(BASE_URL) ?>/owner/ingredients.php">Cancel</a>
            <?php endif; ?>
        </form>

        <!-- Stock list -->
        <section class="app-list-card">
            <h2>Stock list</h2>
            <div class="app-table-wrap">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th>Stock</th>
                            <th>Minimum</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php $low = (float) $row['stock_qty'] <= (float) $row['min_stock_level']; ?>
                            <tr>
                                <td><?= e($row['name']) ?><small><?= e($row['unit']) ?></small></td>
                                <td><?= e((string) $row['stock_qty']) ?></td>
                                <td><?= e((string) $row['min_stock_level']) ?></td>
                                <td>
                                    <span class="app-status-badge<?= $low ? ' app-low-badge' : '' ?>">
                                        <?= $row['is_archived'] ? 'Archived' : ($low ? 'Low stock' : 'Healthy') ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="app-table-link" href="?edit=<?= (int) $row['id'] ?>">Edit</a>
                                    <form method="post" class="app-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="action" value="<?= $row['is_archived'] ? 'restore' : 'archive' ?>">
                                        <button class="app-table-link" type="submit"><?= $row['is_archived'] ? 'Restore' : 'Archive' ?></button>
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