<?php
/**
 * Recipe saving (RecipeDB editor). An ingredient change forks a new version and retires the
 * edited one; a change to anything else updates in place.
 */
require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/matching.php';

/**
 * The product a posted ingredient row refers to. The editor posts the picked product id; a name
 * is only a fallback for a row typed but never picked, and must name an existing product.
 */
function resolveRecipeIngredientProduct(PDO $db, int $productId, string $name): int {
    if ($productId > 0) {
        $id = resolveCanonicalProductId($db, $productId);
        $stmt = $db->prepare('SELECT 1 FROM products WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) return $id;
    }
    $stmt = $db->prepare("SELECT id FROM products WHERE name = ? COLLATE NOCASE AND is_dropped = 0
        ORDER BY merges_into IS NOT NULL, type = 'cooked', id DESC LIMIT 1");
    $stmt->execute([$name]);
    $id = (int)$stmt->fetchColumn();
    if (!$id) {
        $stmt = $db->prepare('SELECT canonical_product_id FROM product_aliases WHERE raw_name = ? COLLATE NOCASE LIMIT 1');
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
    }
    if (!$id) throw new Exception("Unknown ingredient \"{$name}\": pick it from the product list.");
    return resolveCanonicalProductId($db, $id);
}

/** Sorted, comparable ingredient list. */
function normalizeRecipeIngredients(array $ingredients): array {
    $rows = array_map(fn($i) => ['product_id' => (int)$i['product_id'], 'amount' => round((float)$i['amount'], 6), 'unit' => (string)$i['unit']], $ingredients);
    usort($rows, fn($a, $b) => [$a['product_id'], $a['amount'], $a['unit']] <=> [$b['product_id'], $b['amount'], $b['unit']]);
    return $rows;
}

/** Save the RecipeDB form; returns the id of the active recipe version. */
function saveRecipe(PDO $db, array $post): int {
    $name = trim((string)($post['name'] ?? ''));
    if ($name === '') throw new Exception('Recipe name required.');
    $portions = (int)($post['yield'] ?? 1);
    $instructions = $post['instructions'] ?? '';
    $tags = $post['tags'] ?? '';
    $ingNames = $post['ing_product'] ?? [];
    $ingIds = $post['ing_product_id'] ?? [];
    $ingAmounts = $post['ing_amount'] ?? [];
    $ingUnits = $post['ing_unit'] ?? [];
    $recipeId = (int)($post['recipe_id'] ?? 0);

    $db->beginTransaction();
    try {
        $newIngredients = [];
        foreach ($ingNames as $i => $ingName) {
            $ingName = trim((string)$ingName);
            $pickedId = (int)($ingIds[$i] ?? 0);
            if ($ingName === '' && !$pickedId) continue;
            $newIngredients[] = [
                'product_id' => resolveRecipeIngredientProduct($db, $pickedId, $ingName),
                'amount' => (float)($ingAmounts[$i] ?? 0),
                'unit' => (string)($ingUnits[$i] ?? ''),
            ];
        }
        $newIngredients = normalizeRecipeIngredients($newIngredients);

        $oldRecipe = null;
        $ingredientsChanged = true;
        if ($recipeId > 0) {
            $stmt = $db->prepare('SELECT id, parent_recipe_id FROM recipes WHERE id = ?');
            $stmt->execute([$recipeId]);
            $oldRecipe = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$oldRecipe) throw new Exception('Recipe not found.');
            $stmt = $db->prepare('SELECT product_id, amount, unit FROM recipe_ingredients WHERE recipe_id = ?');
            $stmt->execute([$recipeId]);
            $ingredientsChanged = normalizeRecipeIngredients($stmt->fetchAll(PDO::FETCH_ASSOC)) !== $newIngredients;
        }

        if ($oldRecipe && !$ingredientsChanged) {
            $db->prepare('UPDATE recipes SET name = ?, yield_serves = ?, instructions = ?, tags = ? WHERE id = ?')
               ->execute([$name, $portions, $instructions, $tags, $recipeId]);
            $targetId = $recipeId;
        } else {
            $parentId = null;
            $version = 1;
            if ($oldRecipe) {
                // Fork: retire the edited recipe and anything else active in its lineage, by id, so a
                // rename in the same save cannot leave the old version active
                $parentId = (int)($oldRecipe['parent_recipe_id'] ?: $recipeId);
                $db->prepare('UPDATE recipes SET is_active = 0 WHERE is_active = 1 AND (id = ? OR id = ? OR parent_recipe_id = ?)')
                   ->execute([$recipeId, $parentId, $parentId]);
            } else {
                // A new recipe under an active recipe's name replaces it as its next version
                $stmt = $db->prepare('SELECT id, parent_recipe_id FROM recipes WHERE name = ? AND is_active = 1');
                $stmt->execute([$name]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $parentId = (int)($row['parent_recipe_id'] ?: $row['id']);
                    $db->prepare('UPDATE recipes SET is_active = 0 WHERE id = ?')->execute([$row['id']]);
                }
            }
            if ($parentId) {
                $stmt = $db->prepare('SELECT MAX(version) FROM recipes WHERE id = ? OR parent_recipe_id = ?');
                $stmt->execute([$parentId, $parentId]);
                $version = (int)$stmt->fetchColumn() + 1;
            }

            $db->prepare('INSERT INTO recipes (name, yield_serves, instructions, tags, parent_recipe_id, version, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
               ->execute([$name, $portions, $instructions, $tags, $parentId, $version]);
            $targetId = (int)$db->lastInsertId();

            $insert = $db->prepare('INSERT INTO recipe_ingredients (recipe_id, product_id, amount, unit) VALUES (?, ?, ?, ?)');
            foreach ($newIngredients as $ing) $insert->execute([$targetId, $ing['product_id'], $ing['amount'], $ing['unit']]);
        }

        syncRecipeToProduct($db, $targetId);
        $db->commit();
        return $targetId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
