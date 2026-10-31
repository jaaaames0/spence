<?php
/**
 * Quick Eat catalogue matching: which products a scanned food may link to, and what
 * the review card offers for each item.
 */

/** Products a Quick Eat item may link to: live raw products, as receipt matching uses. */
function quickEatCatalogue(PDO $db): array {
    return $db->query("SELECT id, name, category, base_unit, weight_per_ea, kj_per_100, protein_per_100, fat_per_100, carb_per_100, last_unit_cost
        FROM products WHERE merges_into IS NULL AND is_dropped = 0 AND recipe_id IS NULL AND type != 'cooked' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Turn the model's verdict into what the review card offers: 'link' to a catalogue
 * product it is sure about, 'new' for an unmatched grocery item, or 'none' (meals,
 * and 'similar' guesses, stay as plain log entries so the catalogue stays clean).
 */
function resolveQuickEatMatch(array $item, array $catalogueById): array {
    $id = (int)($item['catalogue_id'] ?? 0);
    $verdict = $item['catalogue_match'] ?? 'new';
    $kind = in_array($item['kind'] ?? '', ['packaged', 'fresh', 'meal'], true) ? $item['kind'] : 'meal';
    $item['kind'] = $kind;
    $item['master'] = 'none';
    unset($item['match']);
    if ($verdict === 'same' && isset($catalogueById[$id])) {
        $p = $catalogueById[$id];
        $item['master'] = 'link';
        $item['match'] = [
            'product_id' => (int)$p['id'],
            'name' => $p['name'],
            'base_unit' => $p['base_unit'],
            'weight_per_ea' => (float)$p['weight_per_ea'],
            'kj_per_100' => (float)$p['kj_per_100'],
            'protein_per_100' => (float)$p['protein_per_100'],
            'fat_per_100' => (float)$p['fat_per_100'],
            'carbs_per_100' => (float)$p['carb_per_100'],
            'last_unit_cost' => (float)$p['last_unit_cost'],
        ];
    } elseif ($verdict === 'new' && $kind !== 'meal') {
        $item['master'] = 'new';
    }
    unset($item['catalogue_match'], $item['catalogue_id']);
    return $item;
}
