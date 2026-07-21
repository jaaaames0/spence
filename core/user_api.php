<?php
/**
 * SPENCE User API v1.0 (Phase 10.0: Identity & Goals)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/energy_calibration.php';
header('Content-Type: application/json');
$db = get_db_connection();

$action = $_POST['action'] ?? '';

try {
    if ($action === 'init_profile') {
        $db->beginTransaction();
        
        $name = $_POST['name'];
        $dob = $_POST['dob'];
        $gender = $_POST['gender'];
        $height = (float)$_POST['height'];
        $activity = (float)$_POST['activity'];
        $budget = (float)$_POST['budget'];
        
        $weight = (float)$_POST['weight'];
        $bf = (float)$_POST['bf'];
        $goal_type = $_POST['goal'];

        // 1. Create Profile
        $stmt = $db->prepare("INSERT INTO user_profiles (name, dob, gender, height_cm, activity_rate, weekly_budget) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $dob, $gender, $height, $activity, $budget]);
        $user_id = $db->lastInsertId();

        // 2. Record First Vitals
        $stmt = $db->prepare("INSERT INTO user_vitals_history (user_id, weight_kg, body_fat_pct) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $weight, $bf]);

        // 3. Calculate Goals (Katch-McArdle)
        $lbm = $weight * (1 - ($bf / 100));
        $bmr = 370 + (21.6 * $lbm);
        $tdee_kcal = $bmr * $activity;
        $tdee_kj = $tdee_kcal * 4.184;
        
        $kj_goal = $tdee_kj;
        if ($goal_type === 'Fat Loss' || $goal_type === 'Weight Loss') $kj_goal -= 2000;
        if ($goal_type === 'Lean Gain') $kj_goal += 1000;
        if ($goal_type === 'High Gain') $kj_goal += 2500;
        if ($goal_type === 'Dirty Bulk') $kj_goal += 4500;

        // New Protein Rule: 1g per lb of LBM
        $target_p = $lbm * 2.20462;
        $protein_kj = $target_p * 16.736;

        // Keep protein at 1g/lb LBM; increasingly aggressive gain modes favour more fat.
        $remaining_kj = max(0, $kj_goal - $protein_kj);
        $fat_share = $goal_type === 'Dirty Bulk' ? 0.5 : ($goal_type === 'High Gain' ? 0.4 : (1 / 3));
        $target_f = ($remaining_kj * $fat_share) / 37.656;
        $target_c = ($remaining_kj * (1 - $fat_share)) / 16.736;

        $stmt = $db->prepare("INSERT INTO user_goals_history (user_id, goal_type, target_kj, target_protein_g, target_fat_g, target_carb_g, cost_limit_daily) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $goal_type, round($kj_goal), round($target_p), round($target_f), round($target_c), round($budget / 7, 2)]);

        $db->commit();
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'update_vitals') {
        $user_id = (int)$_POST['user_id'];
        $weight = (float)$_POST['weight'];
        $bf = (float)$_POST['bf'];

        $stmt = $db->prepare("INSERT INTO user_vitals_history (user_id, weight_kg, body_fat_pct) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $weight, $bf]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'update_goals') {
        $user_id = (int)$_POST['user_id'];
        $kj = (float)$_POST['kj'];
        $p = (float)$_POST['p'];
        $f = (float)$_POST['f'];
        $c = (float)$_POST['c'];
        $cost = (float)$_POST['cost'];
        $goal_type = $_POST['goal_type'] ?? 'Manual Override';

        $stmt = $db->prepare("INSERT INTO user_goals_history (user_id, goal_type, target_kj, target_protein_g, target_fat_g, target_carb_g, cost_limit_daily) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $goal_type, $kj, $p, $f, $c, $cost]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'update_activity') {
        $activity = (float)$_POST['activity'];
        // Update both profile and any future goal calculations
        $db->prepare("UPDATE user_profiles SET activity_rate = ?, activity_rate_override = 1")->execute([$activity]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'set_energy_day_exclusion') {
        $day = $_POST['day'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) throw new Exception('Invalid day.');
        if (($_POST['excluded'] ?? '0') === '1') $db->prepare('INSERT OR IGNORE INTO energy_day_exclusions (day) VALUES (?)')->execute([$day]);
        else $db->prepare('DELETE FROM energy_day_exclusions WHERE day = ?')->execute([$day]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'save_energy_preferences') {
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        $goalAdjustment = (float)($_POST['goal_adjustment_kj'] ?? 0);
        $trainingAdjustment = (float)($_POST['training_adjustment_kj'] ?? 0);
        $db->prepare('INSERT INTO energy_preferences (id, use_calibrated_targets, goal_adjustment_kj, training_adjustment_kj, updated_at) VALUES (1, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET use_calibrated_targets = excluded.use_calibrated_targets, goal_adjustment_kj = excluded.goal_adjustment_kj, training_adjustment_kj = excluded.training_adjustment_kj, updated_at = CURRENT_TIMESTAMP')
           ->execute([$enabled, $goalAdjustment, $trainingAdjustment]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'save_active_plan') {
        $db->beginTransaction();
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        $adjustment = (float)($_POST['goal_adjustment_kj'] ?? 0);
        $trainingAdjustment = (float)($_POST['training_adjustment_kj'] ?? 0);
        $db->prepare('INSERT INTO energy_preferences (id, use_calibrated_targets, goal_adjustment_kj, training_adjustment_kj, updated_at) VALUES (1, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET use_calibrated_targets = excluded.use_calibrated_targets, goal_adjustment_kj = excluded.goal_adjustment_kj, training_adjustment_kj = excluded.training_adjustment_kj, updated_at = CURRENT_TIMESTAMP')->execute([$enabled, $adjustment, $trainingAdjustment]);
        $userId = (int)$db->query('SELECT id FROM user_profiles LIMIT 1')->fetchColumn();
        $weeklyBudget = (float)($_POST['weekly_budget'] ?? 0);
        $db->prepare('UPDATE user_profiles SET weekly_budget = ? WHERE id = ?')->execute([$weeklyBudget, $userId]);
        $db->prepare('INSERT INTO user_goals_history (user_id, goal_type, target_kj, target_protein_g, target_fat_g, target_carb_g, cost_limit_daily) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$userId, $_POST['goal_type'] ?? 'Custom', (float)$_POST['target_kj'], (float)$_POST['p'], (float)$_POST['f'], (float)$_POST['c'], $weeklyBudget / 7]);
        $db->commit();
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete_product') {
        $id = (int)$_POST['id'];
        // Check if in use in inventory or log first? 
        // For now, industrial delete: nuke from products table
        $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
