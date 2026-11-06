<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/mailer.php';

/**
 * Notify the owner once when ingredients used by the sale newly reach low stock.
 *
 * @param list<int> $menuItemIds
 */
function notify_new_low_stock(PDO $pdo, array $menuItemIds): void
{
    $menuItemIds = array_values(array_unique(array_filter(
        array_map(static fn (mixed $id): int => (int) $id, $menuItemIds),
        static fn (int $id): bool => $id > 0
    )));
    if ($menuItemIds === []) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($menuItemIds), '?'));
    $ingredientStatement = $pdo->prepare(
        'SELECT DISTINCT i.id, i.low_stock_notified_at
         FROM ingredients i
         INNER JOIN recipes r ON r.ingredient_id = i.id
         WHERE i.is_archived = 0
           AND r.menu_item_id IN (' . $placeholders . ')'
    );
    $ingredientStatement->execute($menuItemIds);
    $notifiedById = [];
    foreach ($ingredientStatement->fetchAll() as $ingredient) {
        $notifiedById[(int) $ingredient['id']] = $ingredient['low_stock_notified_at'];
    }
    if ($notifiedById === []) {
        return;
    }

    $remainingById = [];
    foreach (analytics_remaining_stock($pdo, []) as $ingredient) {
        $remainingById[(int) $ingredient['id']] = $ingredient;
    }

    $resetStatement = $pdo->prepare(
        'UPDATE ingredients
         SET low_stock_notified_at = NULL
         WHERE id = :id
           AND low_stock_notified_at IS NOT NULL'
    );
    $lowIngredients = [];
    foreach ($notifiedById as $ingredientId => $notifiedAt) {
        $ingredient = $remainingById[$ingredientId] ?? null;
        if ($ingredient === null) {
            continue;
        }
        if ($ingredient['remaining'] > $ingredient['min_stock_level']) {
            if ($notifiedAt !== null) {
                $resetStatement->execute(['id' => $ingredientId]);
            }
            continue;
        }
        if ($notifiedAt === null) {
            $lowIngredients[] = $ingredient;
        }
    }
    if ($lowIngredients === []) {
        return;
    }

    $configPath = __DIR__ . '/../config/mail.php';
    if (!is_file($configPath)) {
        return;
    }
    require_once $configPath;
    if (!defined('MAIL_OWNER_EMAIL') || trim((string) MAIL_OWNER_EMAIL) === '') {
        return;
    }

    $names = array_map(static fn (array $ingredient): string => $ingredient['name'], $lowIngredients);
    $lines = ['The following ingredients are at or below their minimum stock level:', ''];
    foreach ($lowIngredients as $ingredient) {
        $lines[] = $ingredient['name'] . ': estimated remaining ' . $ingredient['remaining'] . ' ' . $ingredient['unit']
            . '; minimum ' . $ingredient['min_stock_level'] . ' ' . $ingredient['unit'] . '.';
    }
    $lines[] = '';
    $lines[] = 'These figures are estimates based on recorded sales and recipe quantities.';
    $subject = 'Low stock: ' . implode(', ', $names);
    $text = implode("\n", $lines);

    if (!send_mail((string) MAIL_OWNER_EMAIL, $subject, $text)) {
        return;
    }

    $markStatement = $pdo->prepare(
        'UPDATE ingredients
         SET low_stock_notified_at = NOW()
         WHERE id = :id
           AND low_stock_notified_at IS NULL'
    );
    foreach ($lowIngredients as $ingredient) {
        $markStatement->execute(['id' => (int) $ingredient['id']]);
    }
}
