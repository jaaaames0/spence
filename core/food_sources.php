<?php
/**
 * SPENCE | Food sources — what was eaten, totalled per food.
 * Feeds the dashboard's top-3 cards and the full leaderboard (log/leaderboard.php).
 */

// 'meal' ranks what was logged; 'ingredient' splits each logged meal across its recipe's
// ingredients (kJ by kJ share, protein by protein share, weight by weight share) so a curry
// credits its chicken and rice. Per food: kj, protein, kg eaten, serves (cooked recipes in
// 'meal' mode only), and times (log entries it appeared in).
function getFoodSources(PDO $db, ?int $days, string $mode): array {
    $window = $days ? "cl.consumed_at >= datetime('now', '-$days days')" : '1 = 1';
    $ingKg = "(CASE WHEN ri.unit = 'ea' THEN ri.amount * ip.weight_per_ea ELSE ri.amount END)";
    $logKg = "(CASE WHEN cl.unit = 'ea' THEN cl.amount * COALESCE(p.weight_per_ea, cl.quick_eat_weight_per_ea, 0) ELSE cl.amount END)";
    // Merged products show as their survivor; recipe forks show as the recipe they forked from
    $named = fn(string $alias) => "COALESCE(
        (SELECT rp.name FROM recipes r JOIN recipes rp ON rp.id = r.parent_recipe_id WHERE r.id = $alias.recipe_id),
        (SELECT name FROM products WHERE id = $alias.merges_into), $alias.name)";
    if ($mode === 'ingredient') {
        $sql = "WITH ing AS (
                SELECT ri.recipe_id, {$named('ip')} AS name, $ingKg AS kg,
                       ip.kj_per_100 * $ingKg / 0.1 AS kj, ip.protein_per_100 * $ingKg / 0.1 AS protein
                FROM recipe_ingredients ri JOIN products ip ON ip.id = ri.product_id),
            tot AS (SELECT recipe_id, SUM(kj) AS kj, SUM(protein) AS protein, SUM(kg) AS kg
                    FROM ing GROUP BY recipe_id HAVING SUM(kg) > 0)
            SELECT cl.id, ing.name,
                   COALESCE(cl.kj * ing.kj / NULLIF(tot.kj, 0), 0) AS kj,
                   COALESCE(cl.protein * ing.protein / NULLIF(tot.protein, 0), 0) AS protein,
                   $logKg * ing.kg / tot.kg AS kg, 0 AS serves
            FROM consumption_log cl
            JOIN products p ON p.id = cl.product_id
            JOIN tot ON tot.recipe_id = p.recipe_id
            JOIN ing ON ing.recipe_id = p.recipe_id
            WHERE $window
            UNION ALL
            SELECT cl.id, COALESCE({$named('p')}, cl.name), cl.kj, cl.protein, $logKg, 0
            FROM consumption_log cl
            LEFT JOIN products p ON p.id = cl.product_id
            WHERE $window AND (p.recipe_id IS NULL OR p.recipe_id NOT IN (SELECT recipe_id FROM tot))";
    } else {
        $sql = "SELECT cl.id, COALESCE({$named('p')}, cl.name) AS name, cl.kj, cl.protein, $logKg AS kg,
                   CASE WHEN p.type = 'cooked' THEN (CASE WHEN cl.unit = 'ea' THEN cl.amount ELSE cl.amount / NULLIF(p.weight_per_ea, 0) END) ELSE 0 END AS serves
            FROM consumption_log cl LEFT JOIN products p ON p.id = cl.product_id
            WHERE $window";
    }
    $foods = [];
    $seen = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = $row['name'] ?? '—';
        $food = $foods[$name] ?? ['name' => $name, 'kj' => 0, 'protein' => 0, 'kg' => 0, 'serves' => 0, 'times' => 0];
        foreach (['kj', 'protein', 'kg', 'serves'] as $metric) $food[$metric] += max(0, (float)$row[$metric]);
        // A recipe listing an ingredient twice is still one sitting
        if (!isset($seen[$name][$row['id']])) { $seen[$name][$row['id']] = true; $food['times']++; }
        $foods[$name] = $food;
    }
    return array_values($foods);
}

// Foods ordered by $metric (largest first, zeros dropped), each with its share of the total
function rankFoodSources(array $foods, string $metric, ?int $limit = null): array {
    $sum = array_sum(array_column($foods, $metric));
    $ranked = array_values(array_filter($foods, fn($f) => $f[$metric] > 0));
    usort($ranked, fn($a, $b) => $b[$metric] <=> $a[$metric] ?: strcasecmp($a['name'], $b['name']));
    if ($limit !== null) $ranked = array_slice($ranked, 0, $limit);
    return array_map(fn($f) => $f + ['value' => $f[$metric], 'pct' => $sum > 0 ? round($f[$metric] * 100 / $sum, 1) : 0], $ranked);
}

function formatFoodWeight(float $kg): string {
    return $kg >= 1 ? round($kg, 1) . 'kg' : round($kg * 1000) . 'g';
}
